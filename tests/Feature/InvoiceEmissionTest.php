<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('invoice emission returns 202 Accepted and enqueues ProcessDocumentJob', function () {
    Queue::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'ruc' => '20123456789',
    ]);

    $payload = [
        'company_id' => $company->id,
        'external_id' => 'ORDER-98541',
        'series' => 'F001',
        'correlative' => 452,
        'issue_date' => '2026-09-25',
        'issue_time' => '14:32:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'SERVICIOS TECNOLOGICOS S.A.C.',
            'address' => 'Av. Los Sauces 452, Trujillo',
            'email' => 'facturacion@servicios.pe',
        ],
        'items' => [
            [
                'internal_code' => 'PROD-01',
                'description' => 'Consultoría e integración de software',
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
            'message' => 'Comprobante recibido y encolado para procesamiento.',
            'data' => [
                'external_id' => 'ORDER-98541',
                'document' => 'F001-452',
                'status' => 'pending',
            ],
        ]);

    $this->assertDatabaseHas('documents', [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 452,
        'status' => 'pending',
        'total' => 1180.00,
    ]);

    $this->assertDatabaseHas('document_items', [
        'internal_code' => 'PROD-01',
        'total' => 1180.00,
    ]);

    Queue::assertPushedOn('high', ProcessDocumentJob::class);
});

test('invoice emission with duplicated correlative returns 422 validation error', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    $payload = [
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 100,
        'issue_date' => '2026-09-25',
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client' => [
            'doc_type' => '6',
            'doc_number' => '20600055231',
            'name' => 'CLIENTE TEST',
        ],
        'items' => [
            [
                'description' => 'ITEM 1',
                'unit_code' => 'NIU',
                'quantity' => 1,
                'unit_value' => 100,
                'unit_price' => 118,
                'igv_type' => '10',
                'igv_amount' => 18,
                'total' => 118,
            ],
        ],
        'totals' => [
            'taxable' => 100,
            'igv' => 18,
            'total' => 118,
        ],
    ];

    $response1 = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response1->assertStatus(202);

    $response2 = $this->actingAs($user, 'sanctum')->postJson('/api/v1/invoices', $payload);
    $response2->assertStatus(422)
        ->assertJsonValidationErrors(['correlative']);
});
