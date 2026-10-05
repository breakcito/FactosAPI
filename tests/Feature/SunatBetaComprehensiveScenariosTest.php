<?php

use App\Jobs\ProcessDespatchJob;
use App\Jobs\ProcessDocumentJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\Document;
use App\Models\User;
use App\Services\Greenter\GreenterDespatchBuilder;
use App\Services\Greenter\GreenterInvoiceBuilder;
use App\Services\Greenter\GreenterNoteBuilder;
use App\Services\Greenter\GreenterService;
use App\Services\Greenter\PdfGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function getSeededSunatBetaCompany(): array
{
    $user = User::firstOrCreate(
        ['email' => 'admin@factos.pe'],
        [
            'name' => 'Administrador Factos',
            'password' => bcrypt('password'),
        ]
    );

    $company = Company::firstOrCreate(
        ['ruc' => '20000000001'],
        [
            'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'user_id' => $user->id,
            'business_name' => 'EMPRESA DE PRUEBA SUNAT S.A.C.',
            'trademark_name' => 'FACTOS BETA TEST',
            'address' => 'AV. LOS TESTERS 123 - URB. INDUSTRIAL',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'establishment_code' => '0000',
            'sol_user' => 'MODDATOS',
            'sol_pass' => 'moddatos',
            'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
            'client_secret' => 'test-Hty/M6QshYvPgItX2P0+Kw==',
            'certificate_path' => 'cert.pem',
            'certificate_pass' => '123456',
            'webhook_url' => 'https://webhook.site/demo-factos-receipt',
            'webhook_secret' => 'secret_webhook_factos_test_key_123',
            'is_production' => false,
            'is_active' => true,
            'email_notifications_active' => true,
            'company_copy_emails' => ['contabilidad@empresa-prueba.pe'],
            'send_to_client_email' => true,
            'mail_host' => 'smtp.gmail.com',
            'mail_port' => 587,
            'mail_username' => 'facturacion.empresa.prueba@gmail.com',
            'mail_password' => 'abcd efgh ijkl mnop',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'facturacion.empresa.prueba@gmail.com',
            'mail_from_name' => 'Facturación - Empresa de Prueba S.A.C.',
        ]
    );

    return [$user, $company];
}

test('Caso 1: Factura Gravada Estándar al Contado (01) con IGV 18% para SUNAT Beta', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'operation_type' => '0101',
        'series' => 'F001',
        'correlative' => 101,
        'issue_date' => '2026-10-05',
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'payment_method' => 'contado',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE TEST S.A.C.',
            'address' => 'AV. COMERCIAL 456, LIMA',
            'email' => 'cliente@test.com',
        ],
        'items' => [
            [
                'internal_code' => 'SRV-01',
                'description' => 'Servicio de Consultoría de Software',
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

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);

    $response->assertStatus(202)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'document' => 'F001-101',
                'status' => 'pending',
            ],
        ]);

    Queue::assertPushed(ProcessDocumentJob::class);

    $document = Document::where('company_id', $company->id)->where('correlative', 101)->firstOrFail();
    expect((float) $document->total)->toEqual(1180.00);
    expect($document->establishment_code)->toBe('0000');

    // Verifica construcción del UBL 2.1 con Greenter
    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);
    expect($invoice->getTipoDoc())->toBe('01');
    expect((float) $invoice->getMtoOperGravadas())->toEqual(1000.00);
    expect((float) $invoice->getMtoIGV())->toEqual(180.00);

    // Verifica que GreenterService firme el XML con cert.pem de prueba
    $greenterService = app(GreenterService::class);
    $signResult = $greenterService->signDocument($document);
    expect($signResult['xml'])->toContain('<cbc:UBLVersionID>2.1</cbc:UBLVersionID>');
    expect($signResult['xml'])->toContain('<cbc:CustomizationID>2.0</cbc:CustomizationID>');
    expect($signResult['xml'])->toContain('<ds:Signature');
    expect($signResult['hash'])->toBeString()->and(strlen($signResult['hash']))->toBeGreaterThan(10);

    // Verifica generación de PDF
    $pdfService = app(PdfGeneratorService::class);
    $pdfPath = $pdfService->generate($document);
    expect(Storage::disk('local')->exists($pdfPath))->toBeTrue();
});

test('Caso 2: Factura a Crédito con múltiples Cuotas (01)', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 102,
        'issue_date' => '2026-10-05',
        'issue_time' => '10:30:00',
        'due_date' => '2026-12-05',
        'currency' => 'PEN',
        'payment_method' => 'credito',
        'installments' => [
            ['cuota' => 1, 'amount' => 590.00, 'due_date' => '2026-11-05'],
            ['cuota' => 2, 'amount' => 590.00, 'due_date' => '2026-12-05'],
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE TEST S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'ZZ',
                'description' => 'Licencias de Software Anual',
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

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 102)->firstOrFail();
    expect($document->payment_method)->toBe('credito');
    expect($document->installments)->toHaveCount(2);

    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);
    expect($invoice->getFormaPago()->getTipo())->toBe('Credito');
    expect($invoice->getCuotas())->toHaveCount(2);
});

test('Caso 3: Boleta de Venta Gravada (03) con DNI y Boleta sin documento menor a S/ 700', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    // 3A. Boleta con DNI
    $payloadDni = [
        'company_id' => $company->id,
        'series' => 'B001',
        'correlative' => 201,
        'issue_date' => '2026-10-05',
        'issue_time' => '11:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '1',
            'doc_number' => '44556677',
            'name' => 'JUAN PEREZ GONZALES',
        ],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Mouse Ergonómico Inalámbrico',
                'quantity' => 2,
                'unit_value' => 50.00,
                'unit_price' => 59.00,
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

    $res1 = $this->actingAs($user, 'sanctum')->postJson('/api/v1/boletas', $payloadDni);
    $res1->assertStatus(202);

    // 3B. Boleta sin documento (doc_type: '0', doc_number: '00000000')
    $payloadSinDoc = [
        'company_id' => $company->id,
        'series' => 'B001',
        'correlative' => 202,
        'issue_date' => '2026-10-05',
        'issue_time' => '11:15:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '0',
            'doc_number' => '00000000',
            'name' => 'CLIENTES VARIOS',
        ],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Cable USB-C 2 metros',
                'quantity' => 1,
                'unit_value' => 20.00,
                'unit_price' => 23.60,
                'igv_type' => '10',
                'igv_amount' => 3.60,
                'total' => 23.60,
            ],
        ],
        'totals' => [
            'taxable' => 20.00,
            'igv' => 3.60,
            'total' => 23.60,
        ],
    ];

    $res2 = $this->actingAs($user, 'sanctum')->postJson('/api/v1/boletas', $payloadSinDoc);
    $res2->assertStatus(202);

    $doc = Document::where('company_id', $company->id)->where('correlative', 202)->firstOrFail();
    expect($doc->type_code)->toBe('03');
    expect($doc->client_doc_type)->toBe('0');
});

test('Caso 4: Factura de Exportación (01) con Operación 0200 y Cliente Extranjero', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'operation_type' => '0200',
        'series' => 'F001',
        'correlative' => 103,
        'issue_date' => '2026-10-05',
        'issue_time' => '12:00:00',
        'currency' => 'USD',
        'purchase_order' => 'PO-GLOBAL-9988',
        'client' => [
            'doc_type' => '0',
            'doc_number' => 'US-778899',
            'name' => 'AMAZON WEB SERVICES INC',
            'address' => 'Seattle, WA, USA',
        ],
        'items' => [
            [
                'unit_code' => 'ZZ',
                'description' => 'Software engineering remote services',
                'quantity' => 1,
                'unit_value' => 4500.00,
                'unit_price' => 4500.00,
                'igv_type' => '40',
                'igv_amount' => 0.00,
                'total' => 4500.00,
            ],
        ],
        'totals' => [
            'taxable' => 0.00,
            'exportation' => 4500.00,
            'igv' => 0.00,
            'total' => 4500.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 103)->firstOrFail();
    expect($document->operation_type)->toBe('0200');
    expect((float) $document->total_exportation)->toEqual(4500.00);
    expect($document->currency)->toBe('USD');

    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);
    expect($invoice->getTipoOperacion())->toBe('0200');
    expect((float) $invoice->getMtoOperExportacion())->toEqual(4500.00);
});

test('Caso 5: Factura con Operaciones Exoneradas (20) e Inafectas (30)', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 104,
        'issue_date' => '2026-10-05',
        'issue_time' => '13:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'INSTITUTO CULTURAL S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Libro técnico de arquitectura de software (Exonerado Apéndice I)',
                'quantity' => 2,
                'unit_value' => 80.00,
                'unit_price' => 80.00,
                'igv_type' => '20',
                'igv_amount' => 0.00,
                'total' => 160.00,
            ],
            [
                'unit_code' => 'ZZ',
                'description' => 'Servicio educativo inafecto (Inafecto Art. 2 LIGV)',
                'quantity' => 1,
                'unit_value' => 300.00,
                'unit_price' => 300.00,
                'igv_type' => '30',
                'igv_amount' => 0.00,
                'total' => 300.00,
            ],
        ],
        'totals' => [
            'taxable' => 0.00,
            'exonerated' => 160.00,
            'unaffected' => 300.00,
            'igv' => 0.00,
            'total' => 460.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 104)->firstOrFail();
    expect((float) $document->total_exonerated)->toEqual(160.00);
    expect((float) $document->total_unaffected)->toEqual(300.00);
    expect((float) $document->total)->toEqual(460.00);
});

test('Caso 6: Factura con Operación Gratuita (11) y Leyenda 1002 SUNAT', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 105,
        'issue_date' => '2026-10-05',
        'issue_time' => '13:30:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE DONACION S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Muestra comercial sin valor de venta',
                'quantity' => 5,
                'unit_value' => 50.00,
                'unit_price' => 0.00,
                'igv_type' => '11',
                'igv_amount' => 45.00,
                'total' => 0.00,
            ],
        ],
        'totals' => [
            'taxable' => 0.00,
            'free' => 250.00,
            'igv' => 0.00,
            'total' => 0.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 105)->firstOrFail();
    expect((float) $document->total_free)->toEqual(250.00);
    expect((float) $document->total)->toEqual(0.00);

    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);
    expect((float) $invoice->getMtoOperGratuitas())->toEqual(250.00);

    $has1002 = false;
    foreach ($invoice->getLegends() as $legend) {
        if ($legend->getCode() === '1002') {
            $has1002 = true;
            break;
        }
    }
    expect($has1002)->toBeTrue();
});

test('Caso 7: Factura con Detracción SPOT (Operación 1001 y Código de Bien 022)', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'operation_type' => '1001',
        'series' => 'F001',
        'correlative' => 106,
        'issue_date' => '2026-10-05',
        'issue_time' => '14:00:00',
        'currency' => 'PEN',
        'detraction' => [
            'service_code' => '022',
            'payment_method_code' => '001',
            'percent' => 10.0,
            'amount' => 236.00,
            'bank_account' => '00-000-987654',
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'EMPRESA CONTRATANTE S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'ZZ',
                'description' => 'Servicio de Mantenimiento y Soporte TI Especializado',
                'quantity' => 1,
                'unit_value' => 2000.00,
                'unit_price' => 2360.00,
                'igv_type' => '10',
                'igv_amount' => 360.00,
                'total' => 2360.00,
            ],
        ],
        'totals' => [
            'taxable' => 2000.00,
            'igv' => 360.00,
            'total' => 2360.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 106)->firstOrFail();
    expect($document->operation_type)->toBe('1001');
    expect($document->detraction['service_code'])->toBe('022');
    expect((float) $document->detraction['amount'])->toEqual(236.00);

    $builder = app(GreenterInvoiceBuilder::class);
    $invoice = $builder->build($document);
    expect($invoice->getDetraccion())->not()->toBeNull();
    expect($invoice->getDetraccion()->getCtaBanco())->toBe('00-000-987654');
});

test('Caso 8: Factura con Retención del IGV (Catálogo 53 Código 62)', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 107,
        'issue_date' => '2026-10-05',
        'issue_time' => '14:30:00',
        'currency' => 'PEN',
        'retention' => [
            'code' => '62',
            'percent' => 3.0,
            'amount' => 35.40,
            'base' => 1180.00,
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'AGENTE DE RETENCION S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'ZZ',
                'description' => 'Servicios de publicidad digital',
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

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 107)->firstOrFail();
    expect($document->retention['code'])->toBe('62');
    expect((float) $document->retention['amount'])->toEqual(35.40);
});

test('Caso 9: Factura con Descuento Global y Descuento por Ítem', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 108,
        'issue_date' => '2026-10-05',
        'issue_time' => '15:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE PREFERENCIAL S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Laptop Gamer de Alta Gama',
                'quantity' => 1,
                'unit_value' => 2000.00,
                'unit_price' => 2360.00,
                'igv_type' => '10',
                'igv_amount' => 342.00,
                'total' => 2242.00,
                'attributes' => [
                    'descuento' => 100.00,
                ],
            ],
        ],
        'totals' => [
            'taxable' => 1900.00,
            'discount' => 100.00,
            'igv' => 342.00,
            'total' => 2242.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 108)->firstOrFail();
    expect((float) $document->total_discount)->toEqual(100.00);
    expect((float) $document->total)->toEqual(2242.00);
});

test('Caso 10: Factura con Impuesto a las Bolsas Plásticas (ICBPER)', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 109,
        'issue_date' => '2026-10-05',
        'issue_time' => '15:30:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'RETAIL COMPRADOR S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Bolsa de polietileno biodegradable',
                'quantity' => 10,
                'unit_value' => 0.20,
                'unit_price' => 0.24,
                'igv_type' => '10',
                'igv_amount' => 0.36,
                'total' => 7.36,
                'attributes' => [
                    'icbper' => 5.00,
                ],
            ],
        ],
        'totals' => [
            'taxable' => 2.00,
            'igv' => 0.36,
            'icbper' => 5.00,
            'total' => 7.36,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 109)->firstOrFail();
    expect((float) $document->total_icbper)->toEqual(5.00);
    expect((float) $document->total)->toEqual(7.36);
});

test('Caso 11: Factura con Deducción de Anticipos (Prepayments)', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 110,
        'issue_date' => '2026-10-05',
        'issue_time' => '16:00:00',
        'currency' => 'PEN',
        'prepayments' => [
            [
                'type_code' => '02',
                'number' => 'F001-99',
                'total' => 500.00,
            ],
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE CON ANTICIPO S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'ZZ',
                'description' => 'Entrega final de equipamiento tecnológico',
                'quantity' => 1,
                'unit_value' => 1500.00,
                'unit_price' => 1770.00,
                'igv_type' => '10',
                'igv_amount' => 270.00,
                'total' => 1270.00,
            ],
        ],
        'totals' => [
            'taxable' => 1000.00,
            'igv' => 270.00,
            'total' => 1270.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 110)->firstOrFail();
    expect($document->prepayments)->toHaveCount(1);
    expect($document->prepayments[0]['number'])->toBe('F001-99');
});

test('Caso 12: Factura con Orden de Compra y Placa de Vehículo', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 111,
        'issue_date' => '2026-10-05',
        'issue_time' => '16:30:00',
        'currency' => 'PEN',
        'purchase_order' => 'OC-MINERIA-2026-004',
        'plate_number' => 'B4U-992',
        'establishment_code' => '0000',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'MINERA DEL SUR S.A.A.',
        ],
        'items' => [
            [
                'unit_code' => 'GLI',
                'description' => 'Abastecimiento de combustible Diesel B5',
                'quantity' => 500,
                'unit_value' => 15.00,
                'unit_price' => 17.70,
                'igv_type' => '10',
                'igv_amount' => 1350.00,
                'total' => 8850.00,
            ],
        ],
        'totals' => [
            'taxable' => 7500.00,
            'igv' => 1350.00,
            'total' => 8850.00,
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('correlative', 111)->firstOrFail();
    expect($document->purchase_order)->toBe('OC-MINERIA-2026-004');
    expect($document->plate_number)->toBe('B4U-992');
});

test('Caso 13: Nota de Crédito (07) que anula Factura con Catálogo 09 Motivo 01', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'FC01',
        'correlative' => 301,
        'issue_date' => '2026-10-05',
        'issue_time' => '17:00:00',
        'currency' => 'PEN',
        'note' => [
            'code' => '01', // Anulación de la operación
            'reason' => 'Error en digitación de RUC y datos del cliente',
            'affected_type' => '01',
            'affected_series' => 'F001',
            'affected_correlative' => 101,
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE TEST S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'ZZ',
                'description' => 'Anulación del Servicio de Consultoría de Software',
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

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/credit-notes', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('series', 'FC01')->where('correlative', 301)->firstOrFail();
    expect($document->type_code)->toBe('07');
    expect($document->note_data['code'])->toBe('01');
    expect($document->note_data['affected_series'])->toBe('F001');

    $builder = app(GreenterNoteBuilder::class);
    $note = $builder->build($document);
    expect($note->getTipDocAfectado())->toBe('01');
    expect($note->getNumDocfectado())->toBe('F001-101');
});

test('Caso 14: Nota de Débito (08) por Penalidades o Intereses por Mora', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'FD01',
        'correlative' => 401,
        'issue_date' => '2026-10-05',
        'issue_time' => '17:30:00',
        'currency' => 'PEN',
        'note' => [
            'code' => '01', // Intereses por mora
            'reason' => 'Recargo por intereses de pago fuera de término contractual',
            'affected_type' => '01',
            'affected_series' => 'F001',
            'affected_correlative' => 101,
        ],
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE TEST S.A.C.',
        ],
        'items' => [
            [
                'unit_code' => 'ZZ',
                'description' => 'Intereses moratorios según contrato',
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

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/debit-notes', $payload);
    $response->assertStatus(202);

    $document = Document::where('company_id', $company->id)->where('series', 'FD01')->where('correlative', 401)->firstOrFail();
    expect($document->type_code)->toBe('08');

    $builder = app(GreenterNoteBuilder::class);
    $note = $builder->build($document);
    expect($note->getTipDocAfectado())->toBe('01');
    expect($note->getNumDocfectado())->toBe('F001-101');
});

test('Caso 15: Guía de Remisión Remitente (09) Transporte Privado con Chofer y Vehículo', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'T001',
        'correlative' => 501,
        'issue_date' => '2026-10-05',
        'issue_time' => '18:00:00',
        'transfer_date' => '2026-10-06',
        'transport_mode' => '02', // Privado
        'transfer_reason' => '01', // Venta
        'transfer_description' => 'Traslado de mercadería vendida a cliente final',
        'total_weight' => 150.50,
        'weight_unit' => 'KGM',
        'packages_count' => 10,
        'recipient' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'DESTINATARIO FINAL S.A.C.',
            'address' => 'AV. LOS ALISOS 555, LIMA',
        ],
        'origin' => [
            'ubigeo' => '150101',
            'address' => 'AV. LOS TESTERS 123, LIMA',
        ],
        'destination' => [
            'ubigeo' => '150108',
            'address' => 'CALLE LAS PALMAS 999, LIMA',
        ],
        'driver' => [
            'doc_type' => '1',
            'doc_number' => '44889922',
            'name' => 'PEDRO CONDORI MAMANI',
            'license' => 'Q44889922',
        ],
        'vehicle' => [
            'plate' => 'ABC-123',
            'secondary_plate' => 'REM-456',
        ],
        'items' => [
            [
                'internal_code' => 'ITM-99',
                'description' => 'Cajas de componentes electrónicos de computación',
                'quantity' => 10,
                'unit_code' => 'BX',
            ],
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/despatches', $payload);
    $response->assertStatus(202);

    Queue::assertPushed(ProcessDespatchJob::class);

    $despatch = Despatch::where('company_id', $company->id)->where('correlative', 501)->firstOrFail();
    expect($despatch->transport_mode)->toBe('02');
    expect($despatch->driver_license)->toBe('Q44889922');
    expect($despatch->vehicle_plate)->toBe('ABC-123');

    $builder = app(GreenterDespatchBuilder::class);
    $greenterDespatch = $builder->build($despatch);
    expect($greenterDespatch->getEnvio()->getModTraslado())->toBe('02');
    expect($greenterDespatch->getEnvio()->getChoferes()[0]->getLicencia())->toBe('Q44889922');
});

test('Caso 16: Guía de Remisión Remitente (09) Transporte Público con Transportista MTC', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $payload = [
        'company_id' => $company->id,
        'series' => 'T001',
        'correlative' => 502,
        'issue_date' => '2026-10-05',
        'issue_time' => '18:30:00',
        'transfer_date' => '2026-10-06',
        'transport_mode' => '01', // Público
        'transfer_reason' => '01',
        'total_weight' => 500.00,
        'weight_unit' => 'KGM',
        'packages_count' => 50,
        'recipient' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE LOGISTICA S.A.C.',
            'address' => 'AV. INDUSTRIAL 888',
        ],
        'origin' => [
            'ubigeo' => '150101',
            'address' => 'AV. LOS TESTERS 123, LIMA',
        ],
        'destination' => [
            'ubigeo' => '150115',
            'address' => 'AV. LOS HEROES 100, LIMA',
        ],
        'carrier' => [
            'doc_type' => '6',
            'doc_number' => '20556677881',
            'name' => 'EXPRESO TRANSPORTES DEL PERU S.A.C.',
            'mtc' => 'MTC-998822-REG',
        ],
        'items' => [
            [
                'unit_code' => 'NIU',
                'description' => 'Pallets con cajas cerradas de mercadería general',
                'quantity' => 50,
            ],
        ],
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/despatches', $payload);
    $response->assertStatus(202);

    $despatch = Despatch::where('company_id', $company->id)->where('correlative', 502)->firstOrFail();
    expect($despatch->transport_mode)->toBe('01');
    expect($despatch->carrier_mtc)->toBe('MTC-998822-REG');

    $builder = app(GreenterDespatchBuilder::class);
    $greenterDespatch = $builder->build($despatch);
    expect($greenterDespatch->getEnvio()->getModTraslado())->toBe('01');
    expect($greenterDespatch->getEnvio()->getTransportista()->getNroMtc())->toBe('MTC-998822-REG');
});

test('Caso 17: Comunicación de Baja (Anulación SUNAT) de Comprobante', function () {
    Queue::fake();
    [$user, $company] = getSeededSunatBetaCompany();

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 999,
        'status' => 'accepted',
    ]);

    $payload = [
        'reason' => 'Error en monto facturado acordado con el cliente',
    ];

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/documents/{$document->id}/void", $payload);

    $response->assertStatus(202)
        ->assertJson([
            'status' => 'success',
            'message' => 'Solicitud de anulación recibida y en proceso ante SUNAT.',
        ]);

    $document->refresh();
    expect($document->void_reason)->toBe('Error en monto facturado acordado con el cliente');
});

test('Caso 18: Envío Dinámico de Correos Google SMTP con remitente personalizado de la empresa', function () {
    Mail::fake();
    Storage::fake('local');
    [$user, $company] = getSeededSunatBetaCompany();

    $xmlPath = 'tenants/20000000001/2026/10/01-F001-101.xml';
    $pdfPath = 'tenants/20000000001/2026/10/01-F001-101.pdf';
    Storage::disk('local')->put($xmlPath, '<Invoice>XML Content</Invoice>');
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 PDF Content');

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 101,
        'status' => 'accepted',
        'xml_path' => $xmlPath,
        'pdf_path' => $pdfPath,
        'client_email' => 'cliente@test.com',
        'client_name' => 'CLIENTE TEST S.A.C.',
    ]);

    (new SendInvoiceEmailJob($document))->handle();

    Mail::assertSent(InvoiceMail::class, function ($mail) {
        return $mail->hasTo('cliente@test.com')
            && $mail->hasCc('contabilidad@empresa-prueba.pe')
            && $mail->hasFrom('facturacion.empresa.prueba@gmail.com', 'Facturación - Empresa de Prueba S.A.C.');
    });
});

test('Caso 19: Aislamiento Multi-Tenant (Seguridad entre empresas)', function () {
    [$userA, $companyA] = getSeededSunatBetaCompany();

    // Crear un segundo usuario con otra empresa
    $userB = User::factory()->create(['email' => 'otro@factos.pe']);
    $companyB = Company::factory()->create(['user_id' => $userB->id, 'ruc' => '20999999999']);

    // El usuario B intenta emitir comprobante con company_id de la empresa A -> 422 con rechazo de pertenencia
    $payload = [
        'company_id' => $companyA->id,
        'series' => 'F001',
        'correlative' => 888,
        'issue_date' => '2026-10-05',
        'issue_time' => '19:00:00',
        'currency' => 'PEN',
        'client' => ['doc_type' => '6', 'doc_number' => '20600055231', 'name' => 'TEST'],
        'items' => [['description' => 'Test', 'quantity' => 1, 'unit_value' => 100, 'unit_price' => 118, 'unit_code' => 'NIU', 'igv_type' => '10', 'igv_amount' => 18, 'total' => 118]],
        'totals' => ['taxable' => 100, 'igv' => 18, 'total' => 118],
    ];

    $response = $this->actingAs($userB, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['company_id']);
});
