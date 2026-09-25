<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Greenter\GreenterInvoiceBuilder;
use App\Services\Greenter\GreenterService;
use App\Services\Greenter\PdfGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Document $document
    ) {
        $this->onQueue('high');
    }

    public function handle(
        GreenterInvoiceBuilder $builder,
        GreenterService $greenterService,
        PdfGeneratorService $pdfService
    ): void {
        $this->document->loadMissing(['company', 'items']);

        // 1. Build Greenter Invoice model
        $invoice = $builder->build($this->document);

        // 2. Generate UBL 2.1 XML and digitally sign with company certificate
        $signResult = $greenterService->signDocument($this->document, $invoice);

        $this->document->hash = $signResult['hash'];
        $this->document->xml_path = $signResult['xml_path'];
        $this->document->status = 'signed';

        // 3. Generate PDF representation and save to storage
        $pdfPath = $pdfService->generate($this->document);
        $this->document->pdf_path = $pdfPath;
        $this->document->save();

        // 4. Dispatch job to send the signed XML to SUNAT
        SendDocumentToSunatJob::dispatch($this->document)->onQueue('sunat');
    }

    public function failed(?Throwable $exception): void
    {
        $this->document->update([
            'status' => 'failed',
            'sunat_description' => $exception?->getMessage() ?? 'Error al procesar el comprobante.',
        ]);
    }
}
