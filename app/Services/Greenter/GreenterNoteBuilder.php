<?php

namespace App\Services\Greenter;

use App\Models\Document;
use App\Services\Support\NumeroALetras;
use DateTime;
use DateTimeZone;
use Greenter\Model\Client\Client as GreenterClient;
use Greenter\Model\Company\Address as GreenterAddress;
use Greenter\Model\Company\Company as GreenterCompany;
use Greenter\Model\Sale\Document as GreenterDocRel;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;

class GreenterNoteBuilder
{
    public function build(Document $document): Note
    {
        $company = $document->company;
        $establishmentCode = $document->establishment_code ?: ($company->establishment_code ?: '0000');

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
                    ->setCodLocal($establishmentCode)
            );

        $clientAddress = (new GreenterAddress)
            ->setDireccion($document->client_address ?: '-');

        $greenterClient = (new GreenterClient)
            ->setTipoDoc($document->client_doc_type)
            ->setNumDoc($document->client_doc_number)
            ->setRznSocial($document->client_name)
            ->setAddress($clientAddress);

        $issueDateTime = $this->createDateTime(
            $document->issue_date->format('Y-m-d'),
            $document->issue_time
        );

        $taxable = (float) $document->total_taxable;
        $unaffected = (float) $document->total_unaffected;
        $exonerated = (float) $document->total_exonerated;
        $exportation = (float) ($document->total_exportation ?? 0.00);
        $free = (float) ($document->total_free ?? 0.00);
        $igv = (float) $document->total_igv;
        $icbper = (float) $document->total_icbper;
        $total = (float) $document->total;

        $noteData = $document->note_data ?? [];
        $affectedType = $noteData['affected_type'] ?? ($document->series[0] === 'F' ? '01' : '03');
        $affectedSeries = $noteData['affected_series'] ?? 'F001';
        $affectedCorrelative = (string) ($noteData['affected_correlative'] ?? '1');
        $affectedNumber = sprintf('%s-%s', $affectedSeries, $affectedCorrelative);

        $note = (new Note)
            ->setUblVersion('2.1')
            ->setTipoDoc($document->type_code) // 07: Nota Credito, 08: Nota Debito
            ->setSerie($document->series)
            ->setCorrelativo((string) $document->correlative)
            ->setFechaEmision($issueDateTime)
            ->setTipDocAfectado($affectedType)
            ->setNumDocfectado($affectedNumber)
            ->setCodMotivo($noteData['code'] ?? '01')
            ->setDesMotivo($noteData['reason'] ?? 'Modificación de la operación')
            ->setTipoMoneda($document->currency)
            ->setCompany($greenterCompany)
            ->setClient($greenterClient)
            ->setMtoOperGravadas($taxable)
            ->setMtoOperInafectas($unaffected)
            ->setMtoOperExoneradas($exonerated)
            ->setMtoOperExportacion($exportation > 0 ? $exportation : null)
            ->setMtoOperGratuitas($free > 0 ? $free : null)
            ->setMtoIGV($igv)
            ->setTotalImpuestos($igv + $icbper)
            ->setValorVenta($taxable + $unaffected + $exonerated + $exportation)
            ->setSubTotal($total)
            ->setMtoImpVenta($total);

        if ($icbper > 0) {
            $note->setIcbper($icbper);
        }

        // Guías y Documentos Relacionados
        if (!empty($document->related_documents)) {
            $guias = [];
            foreach ($document->related_documents as $rel) {
                $guias[] = (new GreenterDocRel)
                    ->setTipoDoc($rel['type_code'] ?? '09')
                    ->setNroDoc($rel['number']);
            }
            $note->setGuias($guias);
        }

        // Items
        $details = [];
        $mtoIGVGratuitas = 0.00;
        $hasGratuitas = false;

        foreach ($document->items as $index => $item) {
            $qty = (float) $item->quantity;
            $unitVal = (float) $item->unit_value;
            $unitPrice = (float) $item->unit_price;
            $igvAmount = (float) $item->igv_amount;
            $igvType = $item->igv_type ?: '10';

            $isGratuita = in_array($igvType, ['11', '12', '13', '14', '15', '16', '21', '31', '32', '33', '34', '35', '36', '37']);
            $isGravadaGratuita = in_array($igvType, ['11', '12', '13', '14', '15', '16']);
            $isGravado = in_array($igvType, ['10', '17']);
            $isExport = ($igvType === '40');

            $detail = (new SaleDetail)
                ->setCodProducto($item->internal_code ?: 'ITEM-' . ($index + 1))
                ->setUnidad($item->unit_code ?: 'NIU')
                ->setDescripcion($item->description)
                ->setCantidad($qty)
                ->setTipAfeIgv($igvType);

            if ($isGratuita) {
                $hasGratuitas = true;
                $refVal = $unitVal > 0 ? $unitVal : $unitPrice;
                $detail->setMtoValorUnitario(0.00)
                    ->setMtoPrecioUnitario(0.00)
                    ->setMtoValorGratuito($refVal)
                    ->setMtoValorVenta(0.00);

                if ($isGravadaGratuita) {
                    $baseIgv = $qty * $refVal;
                    $calcIgv = $igvAmount > 0 ? $igvAmount : round($baseIgv * 0.18, 2);
                    $detail->setPorcentajeIgv(18.00)
                        ->setMtoBaseIgv($baseIgv)
                        ->setIgv($calcIgv)
                        ->setTotalImpuestos($calcIgv);
                    $mtoIGVGratuitas += $calcIgv;
                } else {
                    $detail->setPorcentajeIgv(0.00)
                        ->setMtoBaseIgv(0.00)
                        ->setIgv(0.00)
                        ->setTotalImpuestos(0.00);
                }
            } elseif ($isExport) {
                $detail->setMtoValorUnitario($unitVal)
                    ->setMtoPrecioUnitario($unitPrice)
                    ->setPorcentajeIgv(0.00)
                    ->setMtoBaseIgv(0.00)
                    ->setIgv(0.00)
                    ->setTotalImpuestos(0.00)
                    ->setMtoValorVenta($qty * $unitVal);
            } else {
                $baseIgv = $isGravado ? ($qty * $unitVal) : 0.00;
                $porcentajeIgv = $isGravado ? 18.00 : 0.00;

                $detail->setMtoValorUnitario($unitVal)
                    ->setMtoPrecioUnitario($unitPrice)
                    ->setPorcentajeIgv($porcentajeIgv)
                    ->setMtoBaseIgv($baseIgv)
                    ->setIgv($igvAmount)
                    ->setTotalImpuestos($igvAmount)
                    ->setMtoValorVenta($qty * $unitVal);
            }

            if (!empty($item->attributes['icbper']) || !empty($item->attributes['icbper_amount'])) {
                $factor = (float) ($item->attributes['factor_icbper'] ?? 0.50);
                $itemIcbper = (float) ($item->attributes['icbper_amount'] ?? ($qty * $factor));
                $detail->setIcbper($itemIcbper)
                    ->setFactorIcbper($factor);
            }

            $details[] = $detail;
        }
        $note->setDetails($details);

        if ($hasGratuitas && $mtoIGVGratuitas > 0) {
            $note->setMtoIGVGratuitas($mtoIGVGratuitas);
        }

        // Legends
        $legends = [
            (new Legend)
                ->setCode('1000') // Importe total en letras
                ->setValue(NumeroALetras::convert($document->total, $document->currency)),
        ];

        if ($hasGratuitas || $free > 0) {
            $legends[] = (new Legend)
                ->setCode('1002')
                ->setValue('TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE');
        }

        $note->setLegends($legends);

        return $note;
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
