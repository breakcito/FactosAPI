<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Greenter\GreenterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendDocumentToSunatJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Document $document
    ) {
        $this->onQueue('sunat');
    }

    public function handle(GreenterService $greenterService): void
    {
        $this->document->loadMissing(['company']);
        $disk = config('factos.storage_disk', 'local');

        // Check if signed XML exists in storage
        if (!$this->document->xml_path || !Storage::disk($disk)->exists($this->document->xml_path)) {
            Log::error("Signed XML file not found for document {$this->document->id} at {$this->document->xml_path}");
            $this->document->update([
                'status' => 'failed',
                'sunat_description' => 'Archivo XML firmado no encontrado para el envío.',
            ]);

            return;
        }

        $signedXml = Storage::disk($disk)->get($this->document->xml_path);

        try {
            // Send signed XML directly to SUNAT (Idempotent - never re-signs)
            $result = $greenterService->sendSignedXml($this->document, $signedXml);

            if ($result->isSuccess()) {
                // CDR received from SUNAT
                $cdrZip = $result->getCdrZip();
                $cdrPath = $cdrZip ? $greenterService->saveCdr($this->document, $cdrZip) : null;
                $cdrResponse = $result->getCdrResponse();

                $code = $cdrResponse ? (string) $cdrResponse->getCode() : '0';
                $description = $cdrResponse?->getDescription() ?? 'Aceptado por SUNAT.';
                $notes = $cdrResponse?->getNotes() ?? [];

                $this->document->cdr_path = $cdrPath;
                $this->document->sunat_code = $code;
                $this->document->sunat_description = $description;
                $this->document->sunat_notes = $notes;

                if ($code === '0') {
                    // Document ACCEPTED by SUNAT
                    $this->document->status = 'accepted';
                    $this->document->save();

                    SendWebhookJob::dispatch($this->document, 'document.accepted')->onQueue('webhooks');
                    SendInvoiceEmailJob::dispatch($this->document)->onQueue('emails');
                } elseif ((int) $code >= 2000) {
                    // Document REJECTED by SUNAT
                    $this->document->status = 'rejected';
                    $this->document->save();

                    SendWebhookJob::dispatch($this->document, 'document.rejected')->onQueue('webhooks');
                } else {
                    // Other exceptions or codes
                    $this->document->status = 'rejected';
                    $this->document->save();

                    SendWebhookJob::dispatch($this->document, 'document.rejected')->onQueue('webhooks');
                }
            } else {
                // SUNAT returned an error during connection / transport
                $errorMessage = $result->getError()?->getMessage() ?? 'Error desconocido al comunicar con SUNAT.';
                $this->handleSunatFailure($errorMessage);
            }
        } catch (Throwable $e) {
            Log::warning("Exception contacting SUNAT for document {$this->document->id}: {$e->getMessage()}");
            $this->handleSunatFailure($e->getMessage());
        }
    }

    private function handleSunatFailure(string $errorMessage): void
    {
        $this->document->retry_count += 1;
        $this->document->status = 'waiting_sunat';
        $this->document->sunat_description = $errorMessage;

        // Exponential backoff retry delays (1m, 5m, 15m, 1h, 4h)
        $delays = config('factos.retry_delays', [60, 300, 900, 3600, 14400]);
        $attemptIndex = min($this->document->retry_count - 1, count($delays) - 1);
        $secondsDelay = $delays[$attemptIndex];

        $this->document->next_retry_at = now()->addSeconds($secondsDelay);
        $this->document->save();

        SendWebhookJob::dispatch($this->document, 'document.waiting')->onQueue('webhooks');
    }
}
