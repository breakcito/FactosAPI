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
        if (!$this->despatch->xml_path || !Storage::disk($disk)->exists($this->despatch->xml_path)) {
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
            // If already has ticket, verify ticket status
            if ($this->despatch->ticket) {
                $statusResult = ($this->despatch->is_production === $company->is_production)
                    ? $greenterService->checkDespatchTicketStatus($company, $this->despatch->ticket)
                    : $greenterService->checkDespatchTicketStatus($company, $this->despatch->ticket, $this->despatch->is_production);

                if ($statusResult->isSuccess()) {
                    $cdrZip = $statusResult->getCdrZip();
                    $cdrPath = $cdrZip ? $greenterService->saveCdr($this->despatch, $cdrZip) : null;
                    $cdrResponse = $statusResult->getCdrResponse();

                    $this->despatch->update([
                        'status' => 'accepted',
                        'sunat_code' => $cdrResponse ? (string) $cdrResponse->getCode() : '0',
                        'sunat_description' => $cdrResponse?->getDescription() ?? 'Guía aceptada por SUNAT.',
                        'sunat_notes' => $cdrResponse?->getNotes(),
                        'cdr_path' => $cdrPath,
                    ]);

                    SendWebhookJob::dispatch($this->despatch, 'despatch.accepted')->onQueue('webhooks');

                    return;
                }

                $code = (string) $statusResult->getCode();
                if ($code === '98' || $code === '098') {
                    $this->handleRetry('Ticket en proceso en SUNAT.');

                    return;
                }

                $error = $statusResult->getError();
                $this->despatch->update([
                    'status' => 'rejected',
                    'sunat_code' => $code ?: 'UNKNOWN',
                    'sunat_description' => $error?->getMessage() ?? 'Guía rechazada por SUNAT.',
                ]);

                SendWebhookJob::dispatch($this->despatch, 'despatch.rejected')->onQueue('webhooks');

                return;
            }

            // Send via modern GRE REST API
            $result = $greenterService->sendSignedDespatchXml($this->despatch, $signedXml);

            if ($result->isSuccess()) {
                $ticket = $result->getTicket();

                if ($ticket) {
                    $this->despatch->ticket = $ticket;
                    $this->despatch->save();

                    // Immediately query ticket status
                    $statusResult = ($this->despatch->is_production === $company->is_production)
                        ? $greenterService->checkDespatchTicketStatus($company, $ticket)
                        : $greenterService->checkDespatchTicketStatus($company, $ticket, $this->despatch->is_production);

                    if ($statusResult->isSuccess()) {
                        $cdrZip = $statusResult->getCdrZip();
                        $cdrPath = $cdrZip ? $greenterService->saveCdr($this->despatch, $cdrZip) : null;
                        $cdrResponse = $statusResult->getCdrResponse();

                        $this->despatch->update([
                            'status' => 'accepted',
                            'sunat_code' => $cdrResponse ? (string) $cdrResponse->getCode() : '0',
                            'sunat_description' => $cdrResponse?->getDescription() ?? 'Guía aceptada por SUNAT.',
                            'sunat_notes' => $cdrResponse?->getNotes(),
                            'cdr_path' => $cdrPath,
                        ]);

                        SendWebhookJob::dispatch($this->despatch, 'despatch.accepted')->onQueue('webhooks');

                        return;
                    }

                    // Ticket is still processing in SUNAT
                    $this->despatch->update([
                        'status' => 'waiting_sunat',
                        'sunat_code' => (string) $statusResult->getCode() ?: '98',
                        'sunat_description' => 'Guía enviada a SUNAT. Ticket en procesamiento: ' . $ticket,
                        'next_retry_at' => Carbon::now()->addMinutes(1),
                    ]);

                    SendWebhookJob::dispatch($this->despatch, 'despatch.waiting')->onQueue('webhooks');

                    return;
                }
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
            $this->handleRetry('Error de conexión con SUNAT: ' . $e->getMessage());
        }
    }

    private function handleRetry(string $errorMessage): void
    {
        $retries = $this->despatch->retry_count + 1;
        $nextRetryMinutes = min((int) (2 ** $retries), 60);

        $this->despatch->update([
            'status' => 'waiting_sunat',
            'retry_count' => $retries,
            'next_retry_at' => Carbon::now()->addMinutes($nextRetryMinutes),
            'sunat_description' => $errorMessage,
        ]);

        SendWebhookJob::dispatch($this->despatch, 'despatch.waiting')->onQueue('webhooks');
    }
}
