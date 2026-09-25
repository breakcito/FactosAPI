<?php

namespace App\Services\Greenter;

use App\Models\Document;
use App\Services\Support\NumeroALetras;
use DateTime;
use DateTimeZone;
use Greenter\Model\Client\Client as GreenterClient;
use Greenter\Model\Company\Address as GreenterAddress;
use Greenter\Model\Company\Company as GreenterCompany;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;

class GreenterInvoiceBuilder
{
    public function build(Document $document): Invoice
    {
        $company = $document->company;

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
                    ->setCodLocal('0000')
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
        $igv = (float) $document->total_igv;
        $icbper = (float) $document->total_icbper;
        $total = (float) $document->total;

        $invoice = (new Invoice)
            ->setUblVersion('2.1')
            ->setTipoOperacion('0101') // Venta interna (Catálogo 51)
            ->setTipoDoc($document->type_code) // 01: Factura, 03: Boleta
            ->setSerie($document->series)
            ->setCorrelativo((string) $document->correlative)
            ->setFechaEmision($issueDateTime)
            ->setTipoMoneda($document->currency)
            ->setCompany($greenterCompany)
            ->setClient($greenterClient)
            ->setMtoOperGravadas($taxable)
            ->setMtoOperInafectas($unaffected)
            ->setMtoOperExoneradas($exonerated)
            ->setMtoIGV($igv)
            ->setTotalImpuestos($igv + $icbper)
            ->setValorVenta($taxable + $unaffected + $exonerated)
            ->setSubTotal($total)
            ->setMtoImpVenta($total);

        // Fecha de vencimiento si aplica
        if ($document->due_date && $document->due_date->gt($document->issue_date)) {
            $invoice->setFecVencimiento(new DateTime($document->due_date->format('Y-m-d'), new DateTimeZone('America/Lima')));
        }

        // Items
        $details = [];
        foreach ($document->items as $index => $item) {
            $qty = (float) $item->quantity;
            $unitVal = (float) $item->unit_value;
            $unitPrice = (float) $item->unit_price;
            $igvAmount = (float) $item->igv_amount;
            $igvType = $item->igv_type ?: '10';

            $isGravado = in_array($igvType, ['10', '11', '12', '13', '14', '15', '16', '17']);
            $baseIgv = $isGravado ? ($qty * $unitVal) : 0.00;
            $porcentajeIgv = $isGravado ? 18.00 : 0.00;

            $detail = (new SaleDetail)
                ->setCodProducto($item->internal_code ?: 'ITEM-'.($index + 1))
                ->setUnidad($item->unit_code ?: 'NIU')
                ->setDescripcion($item->description)
                ->setCantidad($qty)
                ->setMtoValorUnitario($unitVal)
                ->setMtoPrecioUnitario($unitPrice)
                ->setTipAfeIgv($igvType)
                ->setPorcentajeIgv($porcentajeIgv)
                ->setMtoBaseIgv($baseIgv)
                ->setIgv($igvAmount)
                ->setTotalImpuestos($igvAmount)
                ->setMtoValorVenta($qty * $unitVal);

            $details[] = $detail;
        }
        $invoice->setDetails($details);

        // Legends
        $legends = [
            (new Legend)
                ->setCode('1000') // Importe total en letras
                ->setValue(NumeroALetras::convert($document->total, $document->currency)),
        ];
        $invoice->setLegends($legends);

        return $invoice;
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
