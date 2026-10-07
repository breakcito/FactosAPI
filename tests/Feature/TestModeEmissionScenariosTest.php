<?php

use App\Jobs\ProcessDespatchJob;
use App\Jobs\ProcessDocumentJob;
use App\Jobs\SendDespatchToSunatJob;
use App\Jobs\SendDocumentToSunatJob;
use App\Jobs\VoidDocumentJob;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\Document;
use App\Models\User;
use App\Services\Greenter\GreenterService;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create(['role' => 'developer']);

    // Production Company
    $this->prodCompany = Company::create([
        'user_id' => $this->user->id,
        'ruc' => '20601234567',
        'business_name' => 'EMPRESA REAL PRODUCCION S.A.C.',
        'sol_user' => 'PRODUSER',
        'sol_pass' => 'prodpass123',
        'client_id' => 'prod-client-id-xyz',
        'client_secret' => 'prod-secret-abc',
        'certificate_path' => 'tenants/20601234567/cert.pem',
        'certificate_pass' => '123456',
        'is_production' => true,
        'is_active' => true,
    ]);

    // Beta Company
    $this->betaCompany = Company::create([
        'user_id' => $this->user->id,
        'ruc' => '20609876543',
        'business_name' => 'EMPRESA BETA S.A.C.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'tenants/20609876543/cert.pem',
        'certificate_pass' => '123456',
        'is_production' => false,
        'is_active' => true,
    ]);

    Storage::disk('local')->put(
        'tenants/20601234567/cert.pem',
        file_get_contents(base_path('/cert.pem'))
    );
    Storage::disk('local')->put(
        'tenants/20609876543/cert.pem',
        file_get_contents(base_path('/cert.pem'))
    );
});

test('production company emits test invoice when is_test is true', function () {
    Queue::fake([ProcessDocumentJob::class]);

    $payload = [
        'company_id' => $this->prodCompany->id,
        'is_test' => true,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 1,
        'issue_date' => now()->toDateString(),
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20000000001',
            'name' => 'CLIENTE TEST S.A.C.',
        ],
        'items' => [
            [
                'description' => 'Servicio de prueba',
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
        ->postJson('/api/v1/documents', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.is_production', false)
        ->assertJsonPath('data.is_test', true);

    $doc = Document::where('company_id', $this->prodCompany->id)->where('correlative', 1)->first();
    expect($doc)->not()->toBeNull();
    expect($doc->is_production)->toBeFalse();
    expect($doc->isTest())->toBeTrue();
    expect($doc->isProduction())->toBeFalse();

    Queue::assertPushed(ProcessDocumentJob::class, function ($job) use ($doc) {
        return $job->document->id === $doc->id;
    });
});

test('production company emits test invoice when test_mode is true', function () {
    Queue::fake([ProcessDocumentJob::class]);

    $payload = [
        'company_id' => $this->prodCompany->id,
        'test_mode' => true,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 2,
        'issue_date' => now()->toDateString(),
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20000000001',
            'name' => 'CLIENTE TEST S.A.C.',
        ],
        'items' => [
            [
                'description' => 'Producto de prueba',
                'unit_code' => 'NIU',
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

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/documents', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('data.is_production', false)
        ->assertJsonPath('data.is_test', true);

    $doc = Document::where('company_id', $this->prodCompany->id)->where('correlative', 2)->first();
    expect($doc->is_production)->toBeFalse();
});

test('production company emits production invoice when is_test is not provided', function () {
    Queue::fake([ProcessDocumentJob::class]);

    $payload = [
        'company_id' => $this->prodCompany->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 3,
        'issue_date' => now()->toDateString(),
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20000000001',
            'name' => 'CLIENTE REAL S.A.C.',
        ],
        'items' => [
            [
                'description' => 'Producto Real',
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
        ->postJson('/api/v1/documents', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('data.is_production', true)
        ->assertJsonPath('data.is_test', false);

    $doc = Document::where('company_id', $this->prodCompany->id)->where('correlative', 3)->first();
    expect($doc->is_production)->toBeTrue();
    expect($doc->isProduction())->toBeTrue();
    expect($doc->isTest())->toBeFalse();
});

test('beta company never emits to production even if is_test is false or is_production is true', function () {
    Queue::fake([ProcessDocumentJob::class]);

    $payload = [
        'company_id' => $this->betaCompany->id,
        'is_test' => false,
        'is_production' => true,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 10,
        'issue_date' => now()->toDateString(),
        'issue_time' => '12:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20000000001',
            'name' => 'CLIENTE BETA',
        ],
        'items' => [
            [
                'description' => 'Item Beta',
                'unit_code' => 'NIU',
                'quantity' => 1,
                'unit_value' => 10.00,
                'unit_price' => 11.80,
                'igv_type' => '10',
                'igv_amount' => 1.80,
                'total' => 11.80,
            ],
        ],
        'totals' => [
            'taxable' => 10.00,
            'igv' => 1.80,
            'total' => 11.80,
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/documents', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('data.is_production', false)
        ->assertJsonPath('data.is_test', true);

    $doc = Document::where('company_id', $this->betaCompany->id)->where('correlative', 10)->first();
    expect($doc->is_production)->toBeFalse();
});

test('production company emits test despatch when is_test is true', function () {
    Queue::fake([ProcessDespatchJob::class]);

    $payload = [
        'company_id' => $this->prodCompany->id,
        'is_test' => true,
        'series' => 'T001',
        'correlative' => 1,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'transfer_date' => now()->toDateString(),
        'transport_mode' => '02',
        'transfer_reason' => '01',
        'total_weight' => 25.5,
        'weight_unit' => 'KGM',
        'packages_count' => 3,
        'recipient' => [
            'doc_type' => '6',
            'doc_number' => '20000000001',
            'name' => 'DESTINATARIO TEST S.A.C.',
        ],
        'origin' => [
            'ubigeo' => '150101',
            'address' => 'Av. Origen 123',
        ],
        'destination' => [
            'ubigeo' => '150102',
            'address' => 'Av. Destino 456',
        ],
        'driver' => [
            'doc_type' => '1',
            'doc_number' => '44556677',
            'name' => 'CONDUCTOR PRUEBA',
            'license' => 'Q44556677',
        ],
        'vehicle' => [
            'plate' => 'ABC-123',
        ],
        'items' => [
            [
                'description' => 'Cajas de prueba',
                'unit_code' => 'NIU',
                'quantity' => 3,
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/despatches', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.is_production', false)
        ->assertJsonPath('data.is_test', true);

    $despatch = Despatch::where('company_id', $this->prodCompany->id)->where('correlative', 1)->first();
    expect($despatch)->not()->toBeNull();
    expect($despatch->is_production)->toBeFalse();
    expect($despatch->isTest())->toBeTrue();
});

test('greenter service routes test document to sunat beta and uses beta credentials', function () {
    $service = app(GreenterService::class);

    // Create a test document on the production company
    $testDoc = Document::create([
        'company_id' => $this->prodCompany->id,
        'is_production' => false,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 99,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20000000001',
        'client_name' => 'CLIENTE TEST',
        'total' => 100,
        'status' => 'pending',
    ]);

    // getSee for test document should use SunatEndpoints::FE_BETA
    $see = $service->getSee($this->prodCompany, $testDoc->is_production);
    // getSeeApi for beta
    $api = $service->getSeeApi($this->prodCompany, $testDoc->is_production);

    expect($testDoc->is_production)->toBeFalse();
    expect($see)->not()->toBeNull();
    expect($api)->not()->toBeNull();
});

test('documents index and despatches index can filter by is_production and is_test', function () {
    // Create 1 prod doc and 1 test doc
    Document::create([
        'company_id' => $this->prodCompany->id,
        'is_production' => true,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 501,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20000000001',
        'client_name' => 'PROD DOC',
        'total' => 100,
        'status' => 'accepted',
    ]);

    Document::create([
        'company_id' => $this->prodCompany->id,
        'is_production' => false,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 502,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20000000001',
        'client_name' => 'TEST DOC',
        'total' => 100,
        'status' => 'accepted',
    ]);

    // Filter is_production=true
    $resProd = $this->actingAs($this->user)
        ->getJson('/api/v1/documents?is_production=1');
    $resProd->assertOk();
    $dataProd = $resProd->json('data.data');
    expect(count($dataProd))->toBe(1);
    expect($dataProd[0]['correlative'])->toBe(501);

    // Filter is_test=true
    $resTest = $this->actingAs($this->user)
        ->getJson('/api/v1/documents?is_test=1');
    $resTest->assertOk();
    $dataTest = $resTest->json('data.data');
    expect(count($dataTest))->toBe(1);
    expect($dataTest[0]['correlative'])->toBe(502);
});
