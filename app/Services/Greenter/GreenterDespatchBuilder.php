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
        $cData = $despatch->company_data ?? [];
        $ruc = $cData['ruc'] ?? $company->ruc;
        $businessName = $cData['business_name'] ?? $company->business_name;
        $trademarkName = $cData['trademark_name'] ?? $company->trademark_name ?: $businessName;

        $greenterCompany = (new GreenterCompany)
            ->setRuc($ruc)
            ->setRazonSocial($businessName)
            ->setNombreComercial($trademarkName)
            ->setAddress(
                (new GreenterAddress)
                    ->setUbigueo($cData['ubigeo'] ?? $company->ubigeo ?: '150101')
                    ->setDepartamento($cData['department'] ?? $company->department ?: 'LIMA')
                    ->setProvincia($cData['province'] ?? $company->province ?: 'LIMA')
                    ->setDistrito($cData['district'] ?? $company->district ?: 'LIMA')
                    ->setUrbanizacion('-')
                    ->setDireccion($this->sanitizeXmlText($cData['address'] ?? $company->address ?: 'AV. PRINCIPAL 123'))
                    ->setCodLocal($despatch->establishment_code ?: ($company->establishment_code ?: '0000'))
            );

        $greenterClient = (new GreenterClient)
            ->setTipoDoc($despatch->recipient_doc_type)
            ->setNumDoc($despatch->recipient_doc_number)
            ->setRznSocial($despatch->recipient_name)
            ->setAddress(
                (new GreenterAddress)->setDireccion($this->sanitizeXmlText($despatch->recipient_address ?: '-'))
            );

        $codTraslado = $despatch->transfer_reason;
        $desTraslado = $despatch->transfer_description;

        // En SUNAT GRE 2022 (Error 2554), para los motivos 02 (Compra), 04 (Entre establecimientos) y 07
        // el Destinatario debe ser igual al Remitente. Si el usuario consignó un RUC destinatario distinto
        // en motivo 07 ("Traslado de bienes para transformación"), enviarlo en XML como 13 (Otros) con su descripción.
        if (in_array($codTraslado, ['07', '02'], true) && $despatch->recipient_doc_number !== $ruc) {
            $defaultDesc = $codTraslado === '07'
                ? 'TRASLADO DE BIENES PARA TRANSFORMACION'
                : 'COMPRA DE BIENES';
            $codTraslado = '13';
            $desTraslado = $desTraslado ?: $defaultDesc;
        } elseif ($codTraslado === '13' && empty($desTraslado)) {
            $desTraslado = 'OTROS MOTIVOS DE TRASLADO';
        }

        $tzLima = new DateTimeZone('America/Lima');
        $transferDateObj = new DateTime($despatch->transfer_date->format('Y-m-d'), $tzLima);
        $deliveryDateObj = $despatch->delivery_date
            ? new DateTime($despatch->delivery_date->format('Y-m-d'), $tzLima)
            : $transferDateObj;

        $envio = (new Shipment)
            ->setCodTraslado($codTraslado)
            ->setModTraslado($despatch->transport_mode)
            ->setFecTraslado($transferDateObj)
            ->setFecEntregaBienes($deliveryDateObj)
            ->setPesoTotal((float) $despatch->total_weight)
            ->setUndPesoTotal($despatch->weight_unit)
            ->setNumBultos($despatch->packages_count)
            ->setPartida(new Direction($despatch->origin_ubigeo, $this->sanitizeXmlText($despatch->origin_address)))
            ->setLlegada(new Direction($despatch->destination_ubigeo, $this->sanitizeXmlText($despatch->destination_address)));

        if ($desTraslado) {
            $envio->setDesTraslado($this->sanitizeXmlText($desTraslado));
        }

        // Transporte Público
        if ($despatch->isPublicTransport() && $despatch->carrier_doc_number) {
            $cleanMtc = $despatch->carrier_mtc
                ? trim(preg_replace('/[^A-Z0-9\-]/', '', strtoupper($despatch->carrier_mtc)))
                : '';
            $transp = (new Transportist)
                ->setTipoDoc($despatch->carrier_doc_type ?: '6')
                ->setNumDoc($despatch->carrier_doc_number)
                ->setRznSocial($despatch->carrier_name ?: '-');
            if ($cleanMtc !== '') {
                $transp->setNroMtc($cleanMtc);
            }
            $envio->setTransportista($transp);
        }

        // Transporte Privado
        if ($despatch->isPrivateTransport()) {
            if ($despatch->vehicle_plate) {
                $cleanPlate = preg_replace('/[^A-Z0-9]/', '', strtoupper($despatch->vehicle_plate));
                $vehiculo = (new Vehicle)->setPlaca($cleanPlate ?: $despatch->vehicle_plate);
                if ($despatch->secondary_vehicle_plate) {
                    $cleanSecPlate = preg_replace('/[^A-Z0-9]/', '', strtoupper($despatch->secondary_vehicle_plate));
                    $vehiculo->setSecundarios([
                        (new Vehicle)->setPlaca($cleanSecPlate ?: $despatch->secondary_vehicle_plate),
                    ]);
                }
                $envio->setVehiculo($vehiculo);
            }

            if ($despatch->driver_doc_number) {
                $fullDriverName = trim($despatch->driver_name ?: 'CONDUCTOR');
                $parts = preg_split('/\s+/', $fullDriverName, 2);
                $firstName = $parts[0] ?? 'CONDUCTOR';
                $lastName = $parts[1] ?? $firstName;
                $driver = (new Driver)
                    ->setTipo('Principal')
                    ->setTipoDoc($despatch->driver_doc_type ?: '1')
                    ->setNroDoc($despatch->driver_doc_number)
                    ->setLicencia($despatch->driver_license ?: '-')
                    ->setNombres($firstName)
                    ->setApellidos($lastName);
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

        if ($despatch->observations) {
            $greenterDespatch->setObservacion($this->sanitizeXmlText($despatch->observations));
        }

        // Documentos Relacionados (Factura, Boleta, etc.)
        if (!empty($despatch->related_documents)) {
            $addDocs = [];
            foreach ($despatch->related_documents as $rel) {
                $docNum = $rel['number'] ?? $rel['document_number'] ?? (($rel['series'] ?? '') . '-' . ($rel['correlative'] ?? ''));
                $emisorRuc = $rel['supplier_ruc'] ?? $rel['company_number'] ?? $company->ruc;
                $docType = $rel['type_code'] ?? $rel['document_type'] ?? '01';
                $addDocs[] = (new AdditionalDoc)
                    ->setTipo($docType)
                    ->setNro($docNum)
                    ->setEmisor($emisorRuc);
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

    private function sanitizeXmlText(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
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
