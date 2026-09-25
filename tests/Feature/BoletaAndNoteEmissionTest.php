<?php

use App\Jobs\ProcessDocumentJob;
use App\Jobs\SendDocumentToSunatJob;
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

    // Create a dummy cert for testing
    Storage::disk('local')->put(
        'tenants/20600055231/cert.pem',
        file_get_contents(base_path('/cert.pem'))
    );
});

test('can emit boleta de venta to client with DNI', function () {
    Queue::fake();

    $payload = [
        'company_id' => $this->company->id,
        'external_id' => 'BOL-001',
        'type_code' => '03',
        'series' => 'B001',
        'correlative' => 1,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '1',
            'doc_number' => '72345678',
            'name' => 'JUAN PEREZ LOPEZ',
            'email' => 'juan@gmail.com',
        ],
        'items' => [
            [
                'description' => 'Zapatillas deportivas',
                'unit_code' => 'NIU',
                'quantity' => 1,
                'unit_value' => 100.00,
                'unit_price' => 118.00,
                'igv_type' => '10',
                'igv_amount' => 18.00,
                'total' => 118.00,
            ],
        ],
        'totals' => [
            'taxable' => 100.00,
            'igv' => 18.00,
            'total' => 118.00,
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/boletas', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.document', 'B001-1')
        ->assertJsonPath('data.type_code', '03');

    $this->assertDatabaseHas('documents', [
        'company_id' => $this->company->id,
        'type_code' => '03',
        'series' => 'B001',
        'correlative' => 1,
        'client_doc_type' => '1',
        'client_doc_number' => '72345678',
        'total' => 118.00,
    ]);

    Queue::assertPushed(ProcessDocumentJob::class);
});

test('can emit boleta to anonymous customer without document under 700 soles', function () {
    Queue::fake();

    $payload = [
        'company_id' => $this->company->id,
        'series' => 'B001',
        'correlative' => 2,
        'issue_date' => now()->toDateString(),
        'issue_time' => '11:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '0',
            'doc_number' => '00000000',
            'name' => 'CLIENTES VARIOS',
        ],
        'items' => [
            [
                'description' => 'Consumo de restaurante',
                'unit_code' => 'NIU',
                'quantity' => 1,
                'unit_value' => 50.00,
                'unit_price' => 59.00,
                'igv_type' => '10',
                'igv_amount' => 9.00,
                'total' => 59.00,
            ],
        ],
        'totals' => [
            'taxable' => 50.00,
            'igv' => 9.00,
            'total' => 59.00,
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/documents', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.document', 'B001-2')
        ->assertJsonPath('data.type_code', '03');

    $this->assertDatabaseHas('documents', [
        'company_id' => $this->company->id,
        'type_code' => '03',
        'series' => 'B001',
        'correlative' => 2,
        'client_doc_type' => '0',
    ]);
});

test('can emit nota de credito modifying an invoice', function () {
    Queue::fake();

    $payload = [
        'company_id' => $this->company->id,
        'type_code' => '07',
        'series' => 'FC01',
        'correlative' => 1,
        'issue_date' => now()->toDateString(),
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20100070970',
            'name' => 'SUPERMERCADOS PERUANOS S.A.',
            'address' => 'Av. Javier Prado 123',
        ],
        'note' => [
            'affected_type' => '01',
            'affected_series' => 'F001',
            'affected_correlative' => 100,
            'code' => '01', // Anulación de la operación
            'reason' => 'Anulación total por error en RUC',
        ],
        'items' => [
            [
                'description' => 'Servicio de desarrollo web',
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
        ->postJson('/api/v1/credit-notes', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.document', 'FC01-1')
        ->assertJsonPath('data.type_code', '07');

    $this->assertDatabaseHas('documents', [
        'company_id' => $this->company->id,
        'type_code' => '07',
        'series' => 'FC01',
        'correlative' => 1,
    ]);
});

test('can emit nota de debito modifying an invoice', function () {
    Queue::fake();

    $payload = [
        'company_id' => $this->company->id,
        'type_code' => '08',
        'series' => 'FD01',
        'correlative' => 1,
        'issue_date' => now()->toDateString(),
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20100070970',
            'name' => 'SUPERMERCADOS PERUANOS S.A.',
        ],
        'note' => [
            'affected_type' => '01',
            'affected_series' => 'F001',
            'affected_correlative' => 100,
            'code' => '01', // Intereses por mora
            'reason' => 'Intereses por mora de pago tardío',
        ],
        'items' => [
            [
                'description' => 'Intereses moratorios',
                'unit_code' => 'ZZ',
                'quantity' => 1,
                'unit_value' => 100.00,
                'unit_price' => 118.00,
                'igv_type' => '10',
                'igv_amount' => 18.00,
                'total' => 118.00,
            ],
        ],
        'totals' => [
            'taxable' => 100.00,
            'igv' => 18.00,
            'total' => 118.00,
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/debit-notes', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.document', 'FD01-1')
        ->assertJsonPath('data.type_code', '08');
});

test('process document job generates signed xml and pdf for boleta and credit note', function () {
    $document = Document::create([
        'company_id' => $this->company->id,
        'type_code' => '03',
        'series' => 'B001',
        'correlative' => 50,
        'issue_date' => now()->toDateString(),
        'issue_time' => '15:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '1',
        'client_doc_number' => '44004400',
        'client_name' => 'MARIA GARCIA',
        'total_taxable' => 100.00,
        'total_igv' => 18.00,
        'total' => 118.00,
        'status' => 'pending',
    ]);

    $document->items()->create([
        'description' => 'Servicio prueba',
        'unit_code' => 'NIU',
        'quantity' => 1,
        'unit_value' => 100.00,
        'unit_price' => 118.00,
        'igv_type' => '10',
        'igv_amount' => 18.00,
        'total' => 118.00,
    ]);

    $builder = app(GreenterInvoiceBuilder::class);
    $greenterService = app(GreenterService::class);
    $pdfService = app(PdfGeneratorService::class);

    Queue::fake([SendDocumentToSunatJob::class]);

    $job = new ProcessDocumentJob($document);
    $job->handle($builder, $greenterService, $pdfService);

    $document->refresh();

    expect($document->status)->toBe('signed');
    expect($document->hash)->not()->toBeEmpty();
    expect($document->xml_path)->not()->toBeNull();
    expect($document->pdf_path)->not()->toBeNull();
    expect(Storage::disk('local')->exists($document->xml_path))->toBeTrue();
    expect(Storage::disk('local')->exists($document->pdf_path))->toBeTrue();
});
