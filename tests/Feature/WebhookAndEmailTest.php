<?php

use App\Jobs\SendInvoiceEmailJob;
use App\Jobs\SendWebhookJob;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('SendWebhookJob signs payload with HMAC and records delivery', function () {
    $secret = 'test_webhook_secret_key';
    $webhookUrl = 'https://client-system.test/api/webhook';

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'webhook_url' => $webhookUrl,
        'webhook_secret' => $secret,
    ]);

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 452,
        'status' => 'accepted',
        'hash' => 'p6J9l0qX2/87Hs12k...',
    ]);

    Http::fake([
        $webhookUrl => Http::response(['received' => true], 200),
    ]);

    $job = new SendWebhookJob($document, 'document.accepted');
    $job->handle();

    Http::assertSent(function ($request) use ($webhookUrl, $secret) {
        $body = $request->body();
        $expectedSignature = 'sha256='.hash_hmac('sha256', $body, $secret);

        return $request->url() === $webhookUrl
            && $request->header('X-Factos-Event')[0] === 'document.accepted'
            && $request->header('X-Factos-Signature')[0] === $expectedSignature;
    });

    $this->assertDatabaseHas('webhook_deliveries', [
        'company_id' => $company->id,
        'document_id' => $document->id,
        'event' => 'document.accepted',
        'status' => 'delivered',
        'response_code' => 200,
    ]);
});

test('user can retry failed webhook delivery', function () {
    Queue::fake([SendWebhookJob::class]);

    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);
    $document = Document::factory()->create(['company_id' => $company->id]);
    $webhook = WebhookDelivery::factory()->create([
        'company_id' => $company->id,
        'document_id' => $document->id,
        'status' => 'failed',
    ]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/webhooks/{$webhook->id}/retry");

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'message' => 'Notificación de webhook re-encolada para entrega.',
        ]);

    Queue::assertPushedOn('webhooks', SendWebhookJob::class);
});

test('SendInvoiceEmailJob sends email when notifications are active', function () {
    Mail::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'email_notifications_active' => true,
        'send_to_client_email' => true,
        'company_copy_emails' => ['accounting@mycompany.com'],
    ]);

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'client_email' => 'client@customer.com',
        'status' => 'accepted',
    ]);

    $job = new SendInvoiceEmailJob($document);
    $job->handle();

    Mail::assertSent(InvoiceMail::class, function ($mail) {
        return $mail->hasTo('client@customer.com')
            && $mail->hasCc('accounting@mycompany.com');
    });
});

test('SendInvoiceEmailJob discards sending cleanly without exceptions when notifications are inactive', function () {
    Mail::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'email_notifications_active' => false,
    ]);

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'status' => 'accepted',
    ]);

    $job = new SendInvoiceEmailJob($document);
    $job->handle();

    Mail::assertNothingSent();
});
