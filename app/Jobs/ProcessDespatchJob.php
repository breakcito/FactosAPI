<?php

namespace App\Jobs;

use App\Models\Despatch;
use App\Services\Greenter\GreenterService;
use App\Services\Greenter\PdfGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessDespatchJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Despatch $despatch
    ) {
        $this->onQueue('high');
    }

    public function handle(
        GreenterService $greenterService,
        PdfGeneratorService $pdfService
    ): void {
        $this->despatch->loadMissing(['company', 'items']);

        // 1. Generate UBL 2.1 XML and digitally sign with company certificate
        $signResult = $greenterService->signDespatch($this->despatch);

        $this->despatch->hash = $signResult['hash'];
        $this->despatch->xml_path = $signResult['xml_path'];
        $this->despatch->status = 'signed';

        // 2. Generate PDF representation and save to storage
        $pdfPath = $pdfService->generateDespatchPdf($this->despatch);
        $this->despatch->pdf_path = $pdfPath;
        $this->despatch->save();

        // 3. Dispatch job to send the signed XML to SUNAT
        SendDespatchToSunatJob::dispatch($this->despatch)->onQueue('sunat');
    }

    public function failed(?Throwable $exception): void
    {
        $this->despatch->update([
            'status' => 'failed',
            'sunat_description' => $exception?->getMessage() ?? 'Error al procesar la guía de remisión.',
        ]);
    }
}
