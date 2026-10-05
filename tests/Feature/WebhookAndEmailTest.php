<?php

use App\Jobs\SendInvoiceEmailJob;
use App\Jobs\SendWebhookJob;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Despatch;
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

test('user can retry failed webhook delivery for despatch without type error', function () {
    Queue::fake([SendWebhookJob::class]);

    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);
    $despatch = Despatch::factory()->create(['company_id' => $company->id]);
    $webhook = WebhookDelivery::factory()->create([
        'company_id' => $company->id,
        'document_id' => null,
        'despatch_id' => $despatch->id,
        'status' => 'failed',
    ]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/webhooks/{$webhook->id}/retry");

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'message' => 'Notificación de webhook re-encolada para entrega.',
        ]);

    Queue::assertPushedOn('webhooks', SendWebhookJob::class, function ($job) use ($despatch) {
        return $job->document->id === $despatch->id;
    });
});

test('user cannot retry webhook from another tenant', function () {
    $userA = User::factory()->create();
    $companyA = Company::factory()->create(['user_id' => $userA->id]);
    $webhookA = WebhookDelivery::factory()->create([
        'company_id' => $companyA->id,
        'status' => 'failed',
    ]);

    $userB = User::factory()->create();

    $response = $this->actingAs($userB, 'sanctum')->postJson("/api/v1/webhooks/{$webhookA->id}/retry");
    $response->assertStatus(403);
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

test('SendInvoiceEmailJob uses dynamic Google SMTP settings and sets custom sender headers', function () {
    Mail::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'business_name' => 'DISTRIBUIDORA LIMA S.A.C.',
        'trademark_name' => 'DISTRIBUIDORA LIMA',
        'email_notifications_active' => true,
        'send_to_client_email' => true,
        'company_copy_emails' => ['facturacion@distribuidoralima.com'],
        'mail_host' => 'smtp.gmail.com',
        'mail_port' => 587,
        'mail_username' => 'distribuidoralima@gmail.com',
        'mail_password' => 'abcd efgh ijkl mnop',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'distribuidoralima@gmail.com',
        'mail_from_name' => 'DISTRIBUIDORA LIMA S.A.C.',
    ]);

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'client_email' => 'comprador@cliente.pe',
        'series' => 'F001',
        'correlative' => 500,
        'status' => 'accepted',
    ]);

    expect($company->hasCustomMailConfig())->toBeTrue();
    expect($company->mail_password)->toBe('abcd efgh ijkl mnop');

    $job = new SendInvoiceEmailJob($document);
    $job->handle();

    // Verify dynamic mailer config was created
    $dynamicMailerConfig = config("mail.mailers.company_{$company->id}");
    expect($dynamicMailerConfig)->not()->toBeNull();
    expect($dynamicMailerConfig['host'])->toBe('smtp.gmail.com');
    expect($dynamicMailerConfig['port'])->toBe(587);
    expect($dynamicMailerConfig['username'])->toBe('distribuidoralima@gmail.com');
    expect($dynamicMailerConfig['password'])->toBe('abcd efgh ijkl mnop');
    expect($dynamicMailerConfig['encryption'])->toBe('tls');

    // Verify Mail was sent to client with CC to company
    Mail::assertSent(InvoiceMail::class, function ($mail) {
        $envelope = $mail->envelope();

        return $mail->hasTo('comprador@cliente.pe')
            && $mail->hasCc('facturacion@distribuidoralima.com')
            && $envelope->from->address === 'distribuidoralima@gmail.com'
            && $envelope->from->name === 'DISTRIBUIDORA LIMA S.A.C.'
            && $envelope->replyTo[0]->address === 'distribuidoralima@gmail.com';
    });
});

test('user can configure Google SMTP credentials via API and password is encrypted and hidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/companies/{$company->id}", [
        'mail_host' => 'smtp.gmail.com',
        'mail_port' => 587,
        'mail_username' => 'miempresa@gmail.com',
        'mail_password' => 'secret-app-password',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'miempresa@gmail.com',
        'mail_from_name' => 'MI EMPRESA OFICIAL',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'mail_host' => 'smtp.gmail.com',
                'mail_port' => 587,
                'mail_username' => 'miempresa@gmail.com',
                'mail_from_address' => 'miempresa@gmail.com',
                'mail_from_name' => 'MI EMPRESA OFICIAL',
            ],
        ])
        ->assertJsonMissing(['mail_password']);

    $company->refresh();
    expect($company->mail_password)->toBe('secret-app-password');
    expect($company->hasCustomMailConfig())->toBeTrue();
});
