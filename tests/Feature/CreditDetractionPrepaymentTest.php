<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Greenter\GreenterInvoiceBuilder;
use App\Services\Greenter\GreenterService;
use App\Services\Greenter\PdfGeneratorService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->company = Company::create([
        'user_id' => $this->user->id,
        'ruc' => '20600055231',
        'business_name' => 'EMPRESA PRUEBA S.A.C.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'tenants/20600055231/cert.pem',
        'certificate_pass' => '123456',
        'is_production' => false,
    ]);

    Storage::disk('local')->put(
        'tenants/20600055231/cert.pem',
        file_get_contents(base_path('docs/4-greenter/c-api-with-greenter-example-2/resources/cert.pem'))
    );
});

test('can emit invoice on credit with multiple installments', function () {
    Queue::fake();

    $payload = [
        'company_id' => $this->company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 77,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'payment_method' => 'credito',
        'installments' => [
            ['due_date' => now()->addDays(15)->toDateString(), 'amount' => 500.00],
            ['due_date' => now()->addDays(30)->toDateString(), 'amount' => 680.00],
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20100070970',
            'name' => 'CLIENTE CORPORATIVO S.A.C.',
        ],
        'items' => [
            [
                'description' => 'Servidores cloud dedicados',
                'unit_code' => 'ZZ',
                'quantity' => 1,
                'unit_value' => 1000.00,
                'unit_price' => 1180.00,
                'igv_type' => '10',
                'igv_amount' => 180.00,
                'total' => 1180.00,
            ],
        ],
        'totals' => [
            'taxable' => 1000.00,
            'igv' => 180.00,
            'total' => 1180.00,
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseHas('documents', [
        'company_id' => $this->company->id,
        'payment_method' => 'credito',
        'total' => 1180.00,
    ]);

    $doc = Document::where('company_id', $this->company->id)->where('correlative', 77)->first();
    expect($doc->installments)->toHaveCount(2);
    expect($doc->installments[0]['amount'])->toBe(500);

    // Test signing with Greenter
    $builder = app(GreenterInvoiceBuilder::class);
    $greenterService = app(GreenterService::class);
    $pdfService = app(PdfGeneratorService::class);

    $job = new ProcessDocumentJob($doc);
    $job->handle($builder, $greenterService, $pdfService);

    $doc->refresh();
    expect($doc->status)->toBe('signed');
    expect(Storage::disk('local')->exists($doc->xml_path))->toBeTrue();
});

test('can emit invoice with detraction SPOT and related despatch guide', function () {
    Queue::fake();

    $payload = [
        'company_id' => $this->company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 88,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'detraction' => [
            'payment_method_code' => '001', // Depósito en cuenta
            'bank_account' => '0004-123456', // Cuenta Banco de la Nación
            'service_code' => '022', // Otros servicios empresariales
            'percent' => 10.00,
            'amount' => 118.00,
        ],
        'related_documents' => [
            ['type_code' => '09', 'number' => 'T001-0000012'],
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20100070970',
            'name' => 'CLIENTE CON DETRACCION S.A.C.',
        ],
        'items' => [
            [
                'description' => 'Servicio de mantenimiento industrial',
                'unit_code' => 'ZZ',
                'quantity' => 1,
                'unit_value' => 1000.00,
                'unit_price' => 1180.00,
                'igv_type' => '10',
                'igv_amount' => 180.00,
                'total' => 1180.00,
            ],
        ],
        'totals' => [
            'taxable' => 1000.00,
            'igv' => 180.00,
            'total' => 1180.00,
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(202);

    $doc = Document::where('company_id', $this->company->id)->where('correlative', 88)->first();
    expect($doc->detraction)->not()->toBeNull();
    expect($doc->detraction['bank_account'])->toBe('0004-123456');

    // Test signing with Greenter
    $builder = app(GreenterInvoiceBuilder::class);
    $greenterService = app(GreenterService::class);
    $pdfService = app(PdfGeneratorService::class);

    $job = new ProcessDocumentJob($doc);
    $job->handle($builder, $greenterService, $pdfService);

    $doc->refresh();
    expect($doc->status)->toBe('signed');
    expect(Storage::disk('local')->exists($doc->xml_path))->toBeTrue();
});
