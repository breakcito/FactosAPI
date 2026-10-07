<?php

namespace App\Services\Greenter;

use App\Models\Company;
use App\Models\Despatch;
use App\Models\Document;
use App\Services\Greenter\Ws\SunatSoapClient;
use Greenter\Api;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Greenter\Report\XmlUtils;
use Greenter\See;
use Greenter\Ws\Services\BillSender;
use Greenter\Ws\Services\ExtService;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class GreenterService
{
    public function __construct(
        protected CertificateService $certificateService,
        protected ?GreenterInvoiceBuilder $invoiceBuilder = null,
        protected ?GreenterNoteBuilder $noteBuilder = null,
        protected ?GreenterDespatchBuilder $despatchBuilder = null,
        protected ?GreenterVoidedBuilder $voidedBuilder = null,
    ) {
        $this->invoiceBuilder = $invoiceBuilder ?? new GreenterInvoiceBuilder;
        $this->noteBuilder = $noteBuilder ?? new GreenterNoteBuilder;
        $this->despatchBuilder = $despatchBuilder ?? new GreenterDespatchBuilder;
        $this->voidedBuilder = $voidedBuilder ?? new GreenterVoidedBuilder;
    }

    public function getSee(Company $company, ?bool $isProduction = null): See
    {
        $see = new See;
        $cacheDir = storage_path('framework/cache/greenter');
        if (!File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0755, true);
        }
        $see->setCachePath($cacheDir);

        $certPem = $this->certificateService->getCertificatePem($company);
        $see->setCertificate($certPem);

        $isProd = ($isProduction !== null) ? ($company->is_production && $isProduction) : (bool) $company->is_production;

        $endpoint = $isProd
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA;
        $see->setService($endpoint);

        if ($isProd) {
            $see->setClaveSOL(
                $company->ruc,
                $company->sol_user,
                $company->sol_pass
            );
        } else {
            $user = ($company->is_production || empty($company->sol_user)) ? 'MODDATOS' : $company->sol_user;
            $pass = ($company->is_production || empty($company->sol_pass)) ? 'moddatos' : $company->sol_pass;
            $see->setClaveSOL(
                $company->ruc,
                $user,
                $pass
            );
        }

        return $see;
    }

    /**
     * Build and sign a sales document (Invoice, Boleta, Nota de Crédito, Nota de Débito).
     *
     * @return array{xml: string, hash: string, xml_path: string}
     */
    public function signDocument(Document $document): array
    {
        $saleModel = ($document->isCreditNote() || $document->isDebitNote())
            ? $this->noteBuilder->build($document)
            : $this->invoiceBuilder->build($document);

        $see = $this->getSee($document->company, $document->is_production);
        $xml = (string) $see->getXmlSigned($saleModel);

        $xmlUtils = new XmlUtils;
        $hash = (string) $xmlUtils->getHashSign($xml);

        $xmlPath = $this->getDocumentStoragePath($document, 'xml');
        $disk = config('factos.storage_disk', 'local');
        Storage::disk($disk)->put($xmlPath, $xml);

        return [
            'xml' => $xml,
            'hash' => $hash,
            'xml_path' => $xmlPath,
        ];
    }

    /**
     * Build and sign a Guía de Remisión Electrónica (Despatch).
     *
     * @return array{xml: string, hash: string, xml_path: string}
     */
    public function signDespatch(Despatch $despatch): array
    {
        $greenterDespatch = $this->despatchBuilder->build($despatch);

        $see = $this->getSee($despatch->company, $despatch->is_production);
        $xml = (string) $see->getXmlSigned($greenterDespatch);

        $xmlUtils = new XmlUtils;
        $hash = (string) $xmlUtils->getHashSign($xml);

        $xmlPath = $this->getDespatchStoragePath($despatch, 'xml');
        $disk = config('factos.storage_disk', 'local');
        Storage::disk($disk)->put($xmlPath, $xml);

        return [
            'xml' => $xml,
            'hash' => $hash,
            'xml_path' => $xmlPath,
        ];
    }

    /**
     * Send already signed XML directly to SUNAT without re-signing (Idempotency).
     */
    public function sendSignedXml(Document|Despatch $model, string $signedXml): BillResult
    {
        $company = $model->company;
        $isProd = $company->is_production && $model->is_production;

        $endpoint = $isProd
            ? ($model instanceof Despatch ? SunatEndpoints::GUIA_PRODUCCION : SunatEndpoints::FE_PRODUCCION)
            : ($model instanceof Despatch ? SunatEndpoints::GUIA_BETA : SunatEndpoints::FE_BETA);

        $soapClient = new SunatSoapClient;
        $soapClient->setService($endpoint);

        if ($isProd) {
            $soapClient->setCredentials(
                $company->ruc . $company->sol_user,
                $company->sol_pass
            );
        } else {
            $user = ($company->is_production || empty($company->sol_user)) ? 'MODDATOS' : $company->sol_user;
            $pass = ($company->is_production || empty($company->sol_pass)) ? 'moddatos' : $company->sol_pass;
            $soapClient->setCredentials(
                $company->ruc . $user,
                $pass
            );
        }

        $sender = new BillSender;
        $sender->setClient($soapClient);

        $filename = $model->getSunatFileName();

        /** @var BillResult $result */
        $result = $sender->send($filename, $signedXml);

        return $result;
    }

    public function getSeeApi(Company $company, ?bool $isProduction = null): Api
    {
        $isProd = ($isProduction !== null) ? ($company->is_production && $isProduction) : (bool) $company->is_production;

        $endpoints = $isProd
            ? [
                'auth' => 'https://api-seguridad.sunat.gob.pe/v1',
                'cpe' => 'https://api-cpe.sunat.gob.pe/v1',
            ]
            : [
                'auth' => 'https://gre-test.nubefact.com/v1',
                'cpe' => 'https://gre-test.nubefact.com/v1',
            ];

        $api = new Api($endpoints);

        $certPem = $this->certificateService->getCertificatePem($company);
        $api->setCertificate($certPem);

        if ($isProd) {
            $api->setClaveSOL(
                $company->ruc,
                $company->sol_user,
                $company->sol_pass
            );
            $clientId = $company->client_id ?: 'test-85e5b0ae-255c-4891-a595-0b98c65c9854';
            $clientSecret = $company->client_secret ?: 'test-Hty/M6QshYvPgItX2P0+Kw==';
        } else {
            $user = ($company->is_production || empty($company->sol_user)) ? 'MODDATOS' : $company->sol_user;
            $pass = ($company->is_production || empty($company->sol_pass)) ? 'moddatos' : $company->sol_pass;
            $api->setClaveSOL(
                $company->ruc,
                $user,
                $pass
            );
            $clientId = ($company->is_production || empty($company->client_id))
                ? 'test-85e5b0ae-255c-4891-a595-0b98c65c9854'
                : $company->client_id;
            $clientSecret = ($company->is_production || empty($company->client_secret))
                ? 'test-Hty/M6QshYvPgItX2P0+Kw=='
                : $company->client_secret;
        }

        $api->setApiCredentials($clientId, $clientSecret);

        return $api;
    }

    /**
     * Send signed Despatch XML to SUNAT via GRE REST API.
     */
    public function sendSignedDespatchXml(Despatch $despatch, string $signedXml): SummaryResult
    {
        $api = $this->getSeeApi($despatch->company, $despatch->is_production);
        $filename = $despatch->getSunatFileName();

        /** @var SummaryResult $result */
        $result = $api->sendXml($filename, $signedXml);

        return $result;
    }

    /**
     * Check status of a ticket issued by SUNAT for Despatch via GRE REST API.
     */
    public function checkDespatchTicketStatus(Company $company, string $ticket, ?bool $isProduction = null): StatusResult
    {
        $api = $this->getSeeApi($company, $isProduction);

        return $api->getStatus($ticket);
    }

    /**
     * Sign and send a voiding communication (RA - Voided or RC - Summary).
     *
     * @return array{result: SummaryResult, xml: string, xml_path: string}
     */
    public function sendVoiding(Document $document, string $reason, int $correlative): array
    {
        $company = $document->company;
        $see = $this->getSee($company, $document->is_production);

        $isFacturaOrNote = $document->isInvoice() || ($document->series[0] === 'F');

        /** @var Voided|Summary $voidModel */
        $voidModel = $isFacturaOrNote
            ? $this->voidedBuilder->buildVoided($document, $reason, $correlative)
            : $this->voidedBuilder->buildSummaryVoid($document, $reason, $correlative);

        $xml = (string) $see->getXmlSigned($voidModel);

        $prefix = $isFacturaOrNote ? 'RA' : 'RC';
        $correlativeFormatted = str_pad((string) $correlative, 5, '0', STR_PAD_LEFT);
        $xmlFileName = sprintf('%s-%s-%s-%s.xml', $company->ruc, $prefix, now()->format('Ymd'), $correlativeFormatted);

        $xmlPath = sprintf(
            'tenants/%s/%s/%s/voids/%s',
            $company->ruc,
            now()->format('Y'),
            now()->format('m'),
            $xmlFileName
        );

        $disk = config('factos.storage_disk', 'local');
        Storage::disk($disk)->put($xmlPath, $xml);

        /** @var SummaryResult $result */
        $result = $see->send($voidModel);

        return [
            'result' => $result,
            'xml' => $xml,
            'xml_path' => $xmlPath,
        ];
    }

    /**
     * Check status of a ticket issued by SUNAT (for RA, RC, or Despatch).
     */
    public function checkTicketStatus(Company $company, string $ticket, ?bool $isProduction = null): StatusResult
    {
        $isProd = ($isProduction !== null) ? ($company->is_production && $isProduction) : (bool) $company->is_production;

        $endpoint = $isProd
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA;

        $soapClient = new SunatSoapClient;
        $soapClient->setService($endpoint);

        if ($isProd) {
            $soapClient->setCredentials(
                $company->ruc . $company->sol_user,
                $company->sol_pass
            );
        } else {
            $user = ($company->is_production || empty($company->sol_user)) ? 'MODDATOS' : $company->sol_user;
            $pass = ($company->is_production || empty($company->sol_pass)) ? 'moddatos' : $company->sol_pass;
            $soapClient->setCredentials(
                $company->ruc . $user,
                $pass
            );
        }

        $sender = new ExtService;
        $sender->setClient($soapClient);

        return $sender->getStatus($ticket);
    }

    /**
     * Save CDR ZIP to storage.
     */
    public function saveCdr(Document|Despatch $model, string $cdrZipContent, bool $isVoid = false): string
    {
        $cdrPath = $model instanceof Document
            ? $this->getDocumentStoragePath($model, 'zip', isCdr: true, isVoid: $isVoid)
            : $this->getDespatchStoragePath($model, 'zip', isCdr: true, isVoid: $isVoid);

        $disk = config('factos.storage_disk', 'local');
        Storage::disk($disk)->put($cdrPath, $cdrZipContent);

        return $cdrPath;
    }

    /**
     * Generate storage path for tenant documents:
     * storage/app/tenants/{ruc}/{year}/{month}/{tipo-serie-correlativo}.ext
     */
    public function getDocumentStoragePath(Document $document, string $extension, bool $isCdr = false, bool $isVoid = false): string
    {
        $company = $document->company;
        $year = $document->issue_date->format('Y');
        $month = $document->issue_date->format('m');
        $baseName = sprintf('%s-%s-%s', $document->type_code, $document->series, $document->correlative);
        $filename = $isCdr
            ? sprintf('R-%s.%s', $baseName, $extension)
            : sprintf('%s.%s', $baseName, $extension);

        if ($isVoid) {
            $filename = 'VOID-' . $filename;
        }

        return sprintf('tenants/%s/%s/%s/%s', $company->ruc, $year, $month, $filename);
    }

    public function getDespatchStoragePath(Despatch $despatch, string $extension, bool $isCdr = false, bool $isVoid = false): string
    {
        $company = $despatch->company;
        $year = $despatch->issue_date->format('Y');
        $month = $despatch->issue_date->format('m');
        $baseName = sprintf('%s-%s-%s', $despatch->type_code, $despatch->series, $despatch->correlative);
        $filename = $isCdr
            ? sprintf('R-%s.%s', $baseName, $extension)
            : sprintf('%s.%s', $baseName, $extension);

        if ($isVoid) {
            $filename = 'VOID-' . $filename;
        }

        return sprintf('tenants/%s/%s/%s/despatches/%s', $company->ruc, $year, $month, $filename);
    }
}
