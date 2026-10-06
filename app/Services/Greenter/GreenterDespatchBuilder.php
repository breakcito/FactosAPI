<?php

namespace App\Services\Greenter;

use App\Models\Despatch;
use DateTime;
use DateTimeZone;
use Greenter\Model\Client\Client as GreenterClient;
use Greenter\Model\Company\Address as GreenterAddress;
use Greenter\Model\Company\Company as GreenterCompany;
use Greenter\Model\Despatch\AdditionalDoc;
use Greenter\Model\Despatch\Despatch as GreenterDespatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Driver;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Transportist;
use Greenter\Model\Despatch\Vehicle;

class GreenterDespatchBuilder
{
    public function build(Despatch $despatch): GreenterDespatch
    {
        $company = $despatch->company;

        $greenterCompany = (new GreenterCompany)
            ->setRuc($company->ruc)
            ->setRazonSocial($company->business_name)
            ->setNombreComercial($company->trademark_name ?: $company->business_name)
            ->setAddress(
                (new GreenterAddress)
                    ->setUbigueo($company->ubigeo ?: '150101')
                    ->setDepartamento($company->department ?: 'LIMA')
                    ->setProvincia($company->province ?: 'LIMA')
                    ->setDistrito($company->district ?: 'LIMA')
                    ->setUrbanizacion('-')
                    ->setDireccion($company->address ?: 'AV. PRINCIPAL 123')
                    ->setCodLocal($despatch->establishment_code ?: ($company->establishment_code ?: '0000'))
            );

        $greenterClient = (new GreenterClient)
            ->setTipoDoc($despatch->recipient_doc_type)
            ->setNumDoc($despatch->recipient_doc_number)
            ->setRznSocial($despatch->recipient_name)
            ->setAddress(
                (new GreenterAddress)->setDireccion($despatch->recipient_address ?: '-')
            );

        $envio = (new Shipment)
            ->setCodTraslado($despatch->transfer_reason)
            ->setModTraslado($despatch->transport_mode)
            ->setFecTraslado(new DateTime($despatch->transfer_date->format('Y-m-d'), new DateTimeZone('America/Lima')))
            ->setPesoTotal((float) $despatch->total_weight)
            ->setUndPesoTotal($despatch->weight_unit)
            ->setNumBultos($despatch->packages_count)
            ->setPartida(new Direction($despatch->origin_ubigeo, $despatch->origin_address))
            ->setLlegada(new Direction($despatch->destination_ubigeo, $despatch->destination_address));

        if ($despatch->transfer_description) {
            $envio->setDesTraslado($despatch->transfer_description);
        }

        // Transporte Público
        if ($despatch->isPublicTransport() && $despatch->carrier_doc_number) {
            $transp = (new Transportist)
                ->setTipoDoc($despatch->carrier_doc_type ?: '6')
                ->setNumDoc($despatch->carrier_doc_number)
                ->setRznSocial($despatch->carrier_name ?: '-')
                ->setNroMtc($despatch->carrier_mtc ?: '-');
            $envio->setTransportista($transp);
        }

        // Transporte Privado
        if ($despatch->isPrivateTransport()) {
            if ($despatch->vehicle_plate) {
                $vehiculo = (new Vehicle)->setPlaca($despatch->vehicle_plate);
                if ($despatch->secondary_vehicle_plate) {
                    $vehiculo->setSecundarios([
                        (new Vehicle)->setPlaca($despatch->secondary_vehicle_plate),
                    ]);
                }
                $envio->setVehiculo($vehiculo);
            }

            if ($despatch->driver_doc_number) {
                $driver = (new Driver)
                    ->setTipo('Principal')
                    ->setTipoDoc($despatch->driver_doc_type ?: '1')
                    ->setNroDoc($despatch->driver_doc_number)
                    ->setLicencia($despatch->driver_license ?: '-')
                    ->setNombres($despatch->driver_name ?: '-');
                $envio->setChoferes([$driver]);
            }
        }

        $issueDateTime = $this->createDateTime(
            $despatch->issue_date->format('Y-m-d'),
            $despatch->issue_time
        );

        $greenterDespatch = (new GreenterDespatch)
            ->setVersion('2022')
            ->setTipoDoc($despatch->type_code) // 09: Guía Remitente, 31: Guía Transportista
            ->setSerie($despatch->series)
            ->setCorrelativo((string) $despatch->correlative)
            ->setFechaEmision($issueDateTime)
            ->setCompany($greenterCompany)
            ->setDestinatario($greenterClient)
            ->setEnvio($envio);

        // Documentos Relacionados (Factura, Boleta)
        if (!empty($despatch->related_documents)) {
            $addDocs = [];
            foreach ($despatch->related_documents as $rel) {
                $addDocs[] = (new AdditionalDoc)
                    ->setTipo($rel['type_code'] ?? '01')
                    ->setNro($rel['number'])
                    ->setEmisor($company->ruc);
            }
            $greenterDespatch->setAddDocs($addDocs);
        }

        // Detalle de Items
        $details = [];
        foreach ($despatch->items as $index => $item) {
            $detail = (new DespatchDetail)
                ->setCodigo($item->internal_code ?: 'ITEM-' . ($index + 1))
                ->setDescripcion($item->description)
                ->setUnidad($item->unit_code ?: 'NIU')
                ->setCantidad((float) $item->quantity);
            $details[] = $detail;
        }
        $greenterDespatch->setDetails($details);

        return $greenterDespatch;
    }

    private function createDateTime(string $date, string $time): DateTime
    {
        $timezone = new DateTimeZone('America/Lima');
        $cleanTime = trim($time);
        if (strlen($cleanTime) === 5) {
            $cleanTime .= ':00';
        }

        return new DateTime("{$date} {$cleanTime}", $timezone);
    }
}
