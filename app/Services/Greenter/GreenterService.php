<?php

namespace App\Services\Greenter;

use App\Models\Company;
use App\Models\Document;
use App\Services\Greenter\Ws\SunatSoapClient;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Invoice;
use Greenter\Report\XmlUtils;
use Greenter\See;
use Greenter\Ws\Services\BillSender;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class GreenterService
{
    public function __construct(
        protected CertificateService $certificateService,
    ) {}

    public function getSee(Company $company): See
    {
        $see = new See;
        $cacheDir = storage_path('framework/cache/greenter');
        if (! File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0755, true);
        }
        $see->setCachePath($cacheDir);

        $certPem = $this->certificateService->getCertificatePem($company);
        $see->setCertificate($certPem);

        $endpoint = $company->is_production
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA;
        $see->setService($endpoint);

        return $see;
    }

    /**
     * @return array{xml: string, hash: string, xml_path: string}
     */
    public function signDocument(Document $document, Invoice $invoice): array
    {
        $see = $this->getSee($document->company);
        $xml = $see->getXmlSigned($invoice);

        $xmlUtils = new XmlUtils;
        $hash = $xmlUtils->getHashSign($xml);

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
     * Send already signed XML directly to SUNAT without re-signing (Idempotency).
     */
    public function sendSignedXml(Document $document, string $signedXml): BillResult
    {
        $company = $document->company;
        $endpoint = $company->is_production
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA;

        $soapClient = new SunatSoapClient;
        $soapClient->setService($endpoint);
        $soapClient->setCredentials(
            $company->ruc.$company->sol_user,
            $company->sol_pass
        );

        $sender = new BillSender;
        $sender->setClient($soapClient);

        $filename = $document->getSunatFileName(); // e.g. 20600055231-01-F001-452

        return $sender->send($filename, $signedXml);
    }

    /**
     * Save CDR ZIP to storage.
     */
    public function saveCdr(Document $document, string $cdrZipContent): string
    {
        $cdrPath = $this->getDocumentStoragePath($document, 'zip', isCdr: true);
        $disk = config('factos.storage_disk', 'local');
        Storage::disk($disk)->put($cdrPath, $cdrZipContent);

        return $cdrPath;
    }

    /**
     * Generates structured storage path per tenant:
     * storage/app/tenants/{ruc}/{year}/{month}/{tipo-serie-correlativo}.ext
     * or for CDR:
     * storage/app/tenants/{ruc}/{year}/{month}/R-{tipo-serie-correlativo}.zip
     */
    public function getDocumentStoragePath(Document $document, string $extension, bool $isCdr = false): string
    {
        $ruc = $document->company->ruc;
        $year = $document->issue_date->format('Y');
        $month = $document->issue_date->format('m');
        $prefix = $isCdr ? 'R-' : '';
        $baseName = sprintf('%s%s-%s-%s.%s', $prefix, $document->type_code, $document->series, $document->correlative, $extension);

        return "tenants/{$ruc}/{$year}/{$month}/{$baseName}";
    }
}
