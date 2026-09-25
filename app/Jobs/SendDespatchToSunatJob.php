<?php

namespace App\Jobs;

use App\Models\Despatch;
use App\Services\Greenter\GreenterService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendDespatchToSunatJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public Despatch $despatch
    ) {
        $this->onQueue('sunat');
    }

    public function handle(GreenterService $greenterService): void
    {
        $this->despatch->loadMissing('company');
        $company = $this->despatch->company;

        if (in_array($this->despatch->status, ['accepted', 'voided'])) {
            return;
        }

        $disk = config('factos.storage_disk', 'local');
        if (! $this->despatch->xml_path || ! Storage::disk($disk)->exists($this->despatch->xml_path)) {
            Log::error("Guía XML no encontrado para {$this->despatch->id} en {$this->despatch->xml_path}");
            $this->despatch->update([
                'status' => 'failed',
                'sunat_description' => 'Archivo XML firmado no encontrado en storage.',
            ]);

            return;
        }

        /** @var string $signedXml */
        $signedXml = Storage::disk($disk)->get($this->despatch->xml_path);

        try {
            $result = $greenterService->sendSignedXml($this->despatch, $signedXml);

            if ($result->isSuccess()) {
                $cdrZip = $result->getCdrZip();
                $cdrPath = null;
                if ($cdrZip) {
                    $cdrPath = $greenterService->saveCdr($this->despatch, $cdrZip);
                }

                $cdrResponse = $result->getCdrResponse();
                $notes = $cdrResponse ? $cdrResponse->getNotes() : [];

                $this->despatch->update([
                    'status' => 'accepted',
                    'sunat_code' => $cdrResponse ? $cdrResponse->getCode() : '0',
                    'sunat_description' => $cdrResponse ? $cdrResponse->getDescription() : 'Guía aceptada por SUNAT.',
                    'sunat_notes' => $notes ?: null,
                    'cdr_path' => $cdrPath,
                ]);

                SendWebhookJob::dispatch($this->despatch, 'despatch.accepted')->onQueue('webhooks');

                return;
            }

            // Error de negocio devuelto por SUNAT (Rechazo tributario)
            $error = $result->getError();
            $code = $error ? $error->getCode() : 'UNKNOWN';
            $message = $error ? $error->getMessage() : 'Error desconocido de SUNAT.';

            $this->despatch->update([
                'status' => 'rejected',
                'sunat_code' => $code,
                'sunat_description' => $message,
            ]);

            SendWebhookJob::dispatch($this->despatch, 'despatch.rejected')->onQueue('webhooks');

        } catch (Throwable $e) {
            $retries = $this->despatch->retry_count + 1;
            $nextRetryMinutes = min((int) (2 ** $retries), 60);

            $this->despatch->update([
                'status' => 'waiting_sunat',
                'retry_count' => $retries,
                'next_retry_at' => Carbon::now()->addMinutes($nextRetryMinutes),
                'sunat_description' => 'Error de conexión con SUNAT: '.$e->getMessage(),
            ]);

            SendWebhookJob::dispatch($this->despatch, 'despatch.waiting')->onQueue('webhooks');
        }
    }
}
