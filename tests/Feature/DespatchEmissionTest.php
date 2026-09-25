<?php

use App\Jobs\ProcessDespatchJob;
use App\Jobs\SendDespatchToSunatJob;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\User;
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
        file_get_contents(base_path('/cert.pem'))
    );
});

test('can emit public transport despatch guide', function () {
    Queue::fake();

    $payload = [
        'company_id' => $this->company->id,
        'type_code' => '09',
        'series' => 'T001',
        'correlative' => 1,
        'issue_date' => now()->toDateString(),
        'issue_time' => '08:30:00',
        'transfer_date' => now()->toDateString(),
        'transport_mode' => '01', // Public
        'transfer_reason' => '01', // Venta
        'total_weight' => 150.500,
        'weight_unit' => 'KGM',
        'packages_count' => 5,
        'recipient' => [
            'doc_type' => '6',
            'doc_number' => '20100070970',
            'name' => 'DISTRIBUIDORA NORTE S.A.C.',
            'address' => 'Av. Larco 456, Trujillo',
        ],
        'origin' => [
            'ubigeo' => '150101',
            'address' => 'Av. Industrial 123, Lima',
        ],
        'destination' => [
            'ubigeo' => '130101',
            'address' => 'Av. Larco 456, Trujillo',
        ],
        'carrier' => [
            'doc_number' => '20415932376',
            'name' => 'TRANSPORTES VELOZ S.A.C.',
            'mtc' => 'MTC-998877',
        ],
        'items' => [
            [
                'internal_code' => 'PROD-01',
                'description' => 'Televisores LED 55 pulgadas',
                'unit_code' => 'NIU',
                'quantity' => 5,
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/despatches', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.document', 'T001-1')
        ->assertJsonPath('data.type_code', '09');

    $this->assertDatabaseHas('despatches', [
        'company_id' => $this->company->id,
        'series' => 'T001',
        'correlative' => 1,
        'transport_mode' => '01',
        'carrier_name' => 'TRANSPORTES VELOZ S.A.C.',
    ]);

    Queue::assertPushed(ProcessDespatchJob::class);
});

test('can emit private transport despatch guide and sign with greenter', function () {
    $payload = [
        'company_id' => $this->company->id,
        'type_code' => '09',
        'series' => 'T001',
        'correlative' => 2,
        'issue_date' => now()->toDateString(),
        'issue_time' => '09:00:00',
        'transfer_date' => now()->toDateString(),
        'transport_mode' => '02', // Private
        'transfer_reason' => '01',
        'total_weight' => 50.000,
        'weight_unit' => 'KGM',
        'packages_count' => 2,
        'recipient' => [
            'doc_type' => '1',
            'doc_number' => '44004400',
            'name' => 'CARLOS SANCHEZ',
            'address' => 'Jr. Puno 789, Lima',
        ],
        'origin' => [
            'ubigeo' => '150101',
            'address' => 'Av. Industrial 123, Lima',
        ],
        'destination' => [
            'ubigeo' => '150101',
            'address' => 'Jr. Puno 789, Lima',
        ],
        'driver' => [
            'doc_type' => '1',
            'doc_number' => '10203040',
            'name' => 'PEDRO CHOFER',
            'license' => 'Q10203040',
        ],
        'vehicle' => [
            'plate' => 'ABC123',
        ],
        'items' => [
            [
                'description' => 'Cajas de herramientas',
                'unit_code' => 'NIU',
                'quantity' => 2,
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/despatches', $payload);

    $response->assertStatus(202);

    $despatch = Despatch::where('company_id', $this->company->id)->where('correlative', 2)->first();
    expect($despatch)->not()->toBeNull();

    // Test signing with Greenter
    $greenterService = app(GreenterService::class);
    $pdfService = app(PdfGeneratorService::class);

    Queue::fake([SendDespatchToSunatJob::class]);

    $job = new ProcessDespatchJob($despatch);
    $job->handle($greenterService, $pdfService);

    $despatch->refresh();
    expect($despatch->status)->toBe('signed');
    expect($despatch->hash)->not()->toBeEmpty();
    expect(Storage::disk('local')->exists($despatch->xml_path))->toBeTrue();
    expect(Storage::disk('local')->exists($despatch->pdf_path))->toBeTrue();
});

test('can query and void despatch guide', function () {
    $despatch = Despatch::create([
        'company_id' => $this->company->id,
        'type_code' => '09',
        'series' => 'T001',
        'correlative' => 5,
        'issue_date' => now()->toDateString(),
        'issue_time' => '10:00:00',
        'transfer_date' => now()->toDateString(),
        'transport_mode' => '01',
        'transfer_reason' => '01',
        'total_weight' => 10.0,
        'weight_unit' => 'KGM',
        'packages_count' => 1,
        'recipient_doc_type' => '6',
        'recipient_doc_number' => '20100070970',
        'recipient_name' => 'CLIENTE S.A.C.',
        'origin_ubigeo' => '150101',
        'origin_address' => 'Lima',
        'destination_ubigeo' => '150101',
        'destination_address' => 'Lima',
        'status' => 'accepted',
    ]);

    // Query show
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/despatches/{$despatch->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.document_number', 'T001-5');

    // Void
    $voidResponse = $this->actingAs($this->user)
        ->postJson("/api/v1/despatches/{$despatch->id}/void", [
            'reason' => 'Cancelación del traslado',
        ]);

    $voidResponse->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'voided');

    $despatch->refresh();
    expect($despatch->status)->toBe('voided');
    expect($despatch->void_reason)->toBe('Cancelación del traslado');
});
