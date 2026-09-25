<?php

use App\Jobs\ProcessDocumentJob;
use App\Jobs\SendDocumentToSunatJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Jobs\SendWebhookJob;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\User;
use App\Services\Greenter\GreenterInvoiceBuilder;
use App\Services\Greenter\GreenterService;
use App\Services\Greenter\PdfGeneratorService;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('ProcessDocumentJob generates signed XML, PDF and dispatches SendDocumentToSunatJob', function () {
    Queue::fake([SendDocumentToSunatJob::class]);
    Storage::fake('local');

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
        'certificate_path' => '/cert.pem',
    ]);

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 452,
        'status' => 'pending',
    ]);

    DocumentItem::factory()->create([
        'document_id' => $document->id,
        'quantity' => 1,
        'unit_value' => 100,
        'unit_price' => 118,
        'igv_type' => '10',
        'igv_amount' => 18,
        'total' => 118,
    ]);

    $job = new ProcessDocumentJob($document);
    $job->handle(
        app(GreenterInvoiceBuilder::class),
        app(GreenterService::class),
        app(PdfGeneratorService::class)
    );

    $document->refresh();

    expect($document->status)->toBe('signed');
    expect($document->hash)->not->toBeEmpty();
    expect($document->xml_path)->not->toBeNull();
    expect($document->pdf_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($document->xml_path))->toBeTrue();
    expect(Storage::disk('local')->exists($document->pdf_path))->toBeTrue();

    Queue::assertPushedOn('sunat', SendDocumentToSunatJob::class);
});

test('SendDocumentToSunatJob updates status to accepted, saves CDR, and dispatches webhook and email on success', function () {
    Queue::fake([SendWebhookJob::class, SendInvoiceEmailJob::class]);
    Storage::fake('local');

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
        'webhook_url' => 'https://example.com/webhook',
    ]);

    $xmlPath = 'tenants/20123456789/2026/09/01-F001-452.xml';
    Storage::disk('local')->put($xmlPath, '<Invoice>Signed XML</Invoice>');

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 452,
        'status' => 'signed',
        'xml_path' => $xmlPath,
        'hash' => 'ORIGINAL_HASH_123',
    ]);

    // Mock GreenterService
    $cdrResponse = new CdrResponse;
    $cdrResponse->setCode('0');
    $cdrResponse->setDescription('La Factura numero F001-452, ha sido aceptada.');
    $cdrResponse->setNotes([]);

    $billResult = new BillResult;
    $billResult->setSuccess(true);
    $billResult->setCdrZip('MOCK_CDR_ZIP_CONTENT');
    $billResult->setCdrResponse($cdrResponse);

    $mockGreenter = Mockery::mock(GreenterService::class);
    $mockGreenter->shouldReceive('sendSignedXml')
        ->once()
        ->with(Mockery::on(fn ($doc) => $doc->id === $document->id), '<Invoice>Signed XML</Invoice>')
        ->andReturn($billResult);

    $mockGreenter->shouldReceive('saveCdr')
        ->once()
        ->andReturn('tenants/20123456789/2026/09/R-01-F001-452.zip');

    $job = new SendDocumentToSunatJob($document);
    $job->handle($mockGreenter);

    $document->refresh();

    // Verify Idempotency: Hash was preserved, not re-generated
    expect($document->hash)->toBe('ORIGINAL_HASH_123');
    expect($document->status)->toBe('accepted');
    expect($document->sunat_code)->toBe('0');
    expect($document->sunat_description)->toBe('La Factura numero F001-452, ha sido aceptada.');

    Queue::assertPushedOn('webhooks', SendWebhookJob::class);
    Queue::assertPushedOn('emails', SendInvoiceEmailJob::class);
});

test('SendDocumentToSunatJob updates status to waiting_sunat and backoff on connection timeout', function () {
    Queue::fake([SendWebhookJob::class]);
    Storage::fake('local');

    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'ruc' => '20123456789']);

    $xmlPath = 'tenants/20123456789/2026/09/01-F001-452.xml';
    Storage::disk('local')->put($xmlPath, '<Invoice>Signed XML</Invoice>');

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 452,
        'status' => 'signed',
        'xml_path' => $xmlPath,
        'retry_count' => 0,
    ]);

    $mockGreenter = Mockery::mock(GreenterService::class);
    $mockGreenter->shouldReceive('sendSignedXml')
        ->once()
        ->andThrow(new RuntimeException('Connection timed out to SUNAT'));

    $job = new SendDocumentToSunatJob($document);
    $job->handle($mockGreenter);

    $document->refresh();

    expect($document->status)->toBe('waiting_sunat');
    expect($document->retry_count)->toBe(1);
    expect($document->next_retry_at)->not->toBeNull();
    expect($document->sunat_description)->toContain('Connection timed out to SUNAT');

    Queue::assertPushedOn('webhooks', SendWebhookJob::class, function ($job) {
        return $job->event === 'document.waiting';
    });
});

test('RetryWaitingDocumentsCommand re-enqueues eligible waiting documents', function () {
    Queue::fake([SendDocumentToSunatJob::class]);

    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'status' => 'waiting_sunat',
        'next_retry_at' => now()->subMinute(),
        'retry_count' => 1,
    ]);

    $this->artisan('documents:retry-waiting')
        ->expectsOutput('Re-enqueued 1 waiting documents for SUNAT processing.')
        ->assertExitCode(0);

    Queue::assertPushedOn('sunat', SendDocumentToSunatJob::class);
});
