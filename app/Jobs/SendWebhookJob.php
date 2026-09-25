<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Document $document,
        public string $event
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $this->document->loadMissing(['company']);
        $company = $this->document->company;

        if (empty($company->webhook_url)) {
            return;
        }

        $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');

        $payload = [
            'event' => $this->event,
            'timestamp' => now()->toISOString(),
            'data' => [
                'id' => $this->document->id,
                'external_id' => $this->document->external_id,
                'document_type' => $this->document->type_code,
                'series' => $this->document->series,
                'correlative' => $this->document->correlative,
                'status' => $this->document->status,
                'sunat' => [
                    'code' => $this->document->sunat_code ?? ($this->document->status === 'accepted' ? '0' : null),
                    'description' => $this->document->sunat_description ?? '',
                ],
                'hash' => $this->document->hash,
                'links' => [
                    'xml' => "{$baseUrl}/api/v1/documents/{$this->document->id}/xml",
                    'cdr' => "{$baseUrl}/api/v1/documents/{$this->document->id}/cdr",
                    'pdf' => "{$baseUrl}/api/v1/documents/{$this->document->id}/pdf",
                ],
            ],
        ];

        $bodyRaw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secret = $company->webhook_secret ?? '';
        $signature = 'sha256='.hash_hmac('sha256', $bodyRaw ?: '', $secret);

        $delivery = WebhookDelivery::create([
            'company_id' => $company->id,
            'document_id' => $this->document->id,
            'event' => $this->event,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 1,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Factos-Signature' => $signature,
                'X-Factos-Event' => $this->event,
            ])->timeout(15)->withBody($bodyRaw ?: '', 'application/json')->post($company->webhook_url);

            $delivery->update([
                'response_code' => $response->status(),
                'response_body' => substr($response->body(), 0, 2000),
                'status' => $response->successful() ? 'delivered' : 'failed',
            ]);
        } catch (Throwable $e) {
            Log::warning("Failed delivering webhook to {$company->webhook_url}: {$e->getMessage()}");
            $delivery->update([
                'response_code' => 500,
                'response_body' => $e->getMessage(),
                'status' => 'failed',
            ]);
        }
    }
}
