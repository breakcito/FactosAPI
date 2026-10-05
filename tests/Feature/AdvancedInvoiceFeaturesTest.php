<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Greenter\GreenterInvoiceBuilder;
use App\Services\Greenter\PdfGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('can emit export invoice with foreign client, usd currency, and op 0200', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
    ]);

    $payload = [
        'company_id' => $company->id,
        'operation_type' => '0200',
        'series' => 'F001',
        'correlative' => 901,
        'issue_date' => '2026-10-01',
        'issue_time' => '11:00:00',
        'currency' => 'USD',
        'purchase_order' => 'PO-EXPORT-2026',
        'client' => [
            'doc_type' => '0',
            'doc_number' => 'US-CORP-987654',
            'name' => 'ACME GLOBAL CORP USA',
            'address' => '123 Market St, San Francisco, CA',
        ],
        'items' => [
            [
                'internal_code' => 'EXP-01',
                'description' => 'Servicios de desarrollo de software para el exterior',
                'unit_code' => 'ZZ',
                'quantity' => 1,
                'unit_value' => 5000.00,
                'unit_price' => 5000.00,
                'igv_type' => '40',
                'igv_amount' => 0.00,
                'total' => 5000.00,
            ],
        ],
        'totals' => [
            'taxable' => 0.00,
            'exportation' => 5000.00,
            'igv' => 0.00,
            'total' => 5000.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 901)->first();
    expect($document)->not()->toBeNull();
    expect($document->operation_type)->toBe('0200');
    expect((float) $document->total_exportation)->toEqual(5000.00);
    expect($document->purchase_order)->toBe('PO-EXPORT-2026');

    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);

    expect($invoice->getTipoOperacion())->toBe('0200');
    expect((float) $invoice->getMtoOperExportacion())->toEqual(5000.00);
    expect((float) $invoice->getMtoImpVenta())->toEqual(5000.00);
    expect($invoice->getCompra())->toBe('PO-EXPORT-2026');
});

test('can emit invoice with free goods and verifies legend 1002 and gratuitas amount', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
    ]);

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 902,
        'issue_date' => '2026-10-01',
        'issue_time' => '11:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'SERVICIOS TECNOLOGICOS S.A.C.',
        ],
        'items' => [
            [
                'internal_code' => 'SAMPLE-01',
                'description' => 'Muestra comercial sin valor de venta',
                'unit_code' => 'NIU',
                'quantity' => 2,
                'unit_value' => 50.00,
                'unit_price' => 59.00,
                'igv_type' => '11', // Gravado - Retiro por donación o gratuito
                'igv_amount' => 18.00,
                'total' => 0.00,
            ],
        ],
        'totals' => [
            'taxable' => 0.00,
            'free' => 100.00,
            'igv' => 0.00,
            'total' => 0.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 902)->first();
    expect($document)->not()->toBeNull();
    expect((float) $document->total_free)->toEqual(100.00);

    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);

    expect((float) $invoice->getMtoOperGratuitas())->toEqual(100.00);
    expect((float) $invoice->getMtoImpVenta())->toEqual(0.00);

    $detail = $invoice->getDetails()[0];
    expect((float) $detail->getMtoValorGratuito())->toEqual(50.00);
    expect((float) $detail->getMtoValorUnitario())->toEqual(0.00);

    $legends = collect($invoice->getLegends());
    $legend1002 = $legends->firstWhere(fn ($l) => $l->getCode() === '1002');
    expect($legend1002)->not()->toBeNull();
});

test('can emit invoice with retention and discount and verifies charge 62', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
    ]);

    $payload = [
        'company_id' => $company->id,
        'operation_type' => '2001',
        'series' => 'F001',
        'correlative' => 903,
        'issue_date' => '2026-10-01',
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'retention' => [
            'code' => '62',
            'base_amount' => 236.00,
            'percent' => 0.03,
            'amount' => 7.08,
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE RETENCION S.A.C.',
        ],
        'items' => [
            [
                'internal_code' => 'ITEM-RET',
                'description' => 'Servicio sujeto a retención',
                'unit_code' => 'NIU',
                'quantity' => 2,
                'unit_value' => 100.00,
                'unit_price' => 118.00,
                'igv_type' => '10',
                'igv_amount' => 36.00,
                'total' => 236.00,
            ],
        ],
        'totals' => [
            'taxable' => 200.00,
            'igv' => 36.00,
            'total' => 236.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 903)->first();
    expect($document->operation_type)->toBe('2001');
    expect($document->retention)->toHaveKey('amount', 7.08);

    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);

    $descuentos = $invoice->getDescuentos();
    expect($descuentos)->not()->toBeEmpty();
    expect($descuentos[0]->getCodTipo())->toBe('62');
    expect((float) $descuentos[0]->getMonto())->toEqual(7.08);
});

test('pdf includes vehicle plate and purchase order if provided', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
    ]);

    $document = Document::create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 904,
        'issue_date' => '2026-10-01',
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'purchase_order' => 'OC-PETRO-2026',
        'plate_number' => 'ABC-999',
        'client_doc_type' => '6',
        'client_doc_number' => '20600055231',
        'client_name' => 'TRANSPORTE S.A.C.',
        'total_taxable' => 100.00,
        'total_igv' => 18.00,
        'total' => 118.00,
        'status' => 'pending',
    ]);

    $pdfService = app(PdfGeneratorService::class);
    $html = $pdfService->renderHtml($document);

    expect($html)->toContain('OC-PETRO-2026');
    expect($html)->toContain('ABC-999');
});
