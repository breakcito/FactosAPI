<?php

namespace App\Services\Greenter;

use App\Models\Document;
use DateTime;
use DateTimeZone;
use Greenter\Model\Company\Address as GreenterAddress;
use Greenter\Model\Company\Company as GreenterCompany;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;

class GreenterVoidedBuilder
{
    /**
     * Build Comunicación de Baja (RA) for Facturas and Factura Notes.
     */
    public function buildVoided(Document $document, string $reason, int $correlative): Voided
    {
        $company = $document->company;
        $greenterCompany = (new GreenterCompany)
            ->setRuc($company->ruc)
            ->setRazonSocial($company->business_name)
            ->setAddress(
                (new GreenterAddress)
                    ->setUbigueo($company->ubigeo ?: '150101')
                    ->setDireccion($company->address ?: 'AV. PRINCIPAL 123')
            );

        $detail = (new VoidedDetail)
            ->setTipoDoc($document->type_code)
            ->setSerie($document->series)
            ->setCorrelativo((string) $document->correlative)
            ->setDesMotivoBaja($reason);

        $issueDate = new DateTime($document->issue_date->format('Y-m-d'), new DateTimeZone('America/Lima'));
        $today = new DateTime('now', new DateTimeZone('America/Lima'));

        return (new Voided)
            ->setCorrelativo(str_pad((string) $correlative, 5, '0', STR_PAD_LEFT))
            ->setFecGeneracion($issueDate)
            ->setFecComunicacion($today)
            ->setCompany($greenterCompany)
            ->setDetails([$detail]);
    }

    /**
     * Build Resumen Diario de Anulaciones (RC) for Boletas and Boleta Notes.
     */
    public function buildSummaryVoid(Document $document, string $reason, int $correlative): Summary
    {
        $company = $document->company;
        $greenterCompany = (new GreenterCompany)
            ->setRuc($company->ruc)
            ->setRazonSocial($company->business_name)
            ->setAddress(
                (new GreenterAddress)
                    ->setUbigueo($company->ubigeo ?: '150101')
                    ->setDireccion($company->address ?: 'AV. PRINCIPAL 123')
            );

        $detail = (new SummaryDetail)
            ->setTipoDoc($document->type_code)
            ->setSerieNro($document->series.'-'.$document->correlative)
            ->setEstado('3') // 3 = Anulado en Catálogo 19 SUNAT
            ->setClienteTipo($document->client_doc_type)
            ->setClienteNro($document->client_doc_number)
            ->setTotal((float) $document->total)
            ->setMtoOperGravadas((float) $document->total_taxable)
            ->setMtoOperInafectas((float) $document->total_unaffected)
            ->setMtoOperExoneradas((float) $document->total_exonerated)
            ->setMtoIGV((float) $document->total_igv);

        $issueDate = new DateTime($document->issue_date->format('Y-m-d'), new DateTimeZone('America/Lima'));
        $today = new DateTime('now', new DateTimeZone('America/Lima'));

        return (new Summary)
            ->setCorrelativo(str_pad((string) $correlative, 5, '0', STR_PAD_LEFT))
            ->setFecGeneracion($issueDate)
            ->setFecResumen($today)
            ->setCompany($greenterCompany)
            ->setDetails([$detail]);
    }
}
