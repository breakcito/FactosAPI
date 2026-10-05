<?php

use App\Models\Company;
use App\Models\Despatch;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user cannot view or modify another users company, document, or despatch', function () {
    $userA = User::factory()->create();
    $companyA = Company::factory()->create(['user_id' => $userA->id]);

    $userB = User::factory()->create();
    $companyB = Company::factory()->create(['user_id' => $userB->id]);

    $documentA = Document::create([
        'company_id' => $companyA->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 10,
        'issue_date' => '2026-10-01',
        'issue_time' => '10:00:00',
        'currency' => 'PEN',
        'client_doc_type' => '6',
        'client_doc_number' => '20123456789',
        'client_name' => 'CLIENTE TEST A',
        'total' => 100.00,
        'status' => 'accepted',
    ]);

    $despatchA = Despatch::create([
        'company_id' => $companyA->id,
        'type_code' => '09',
        'series' => 'T001',
        'correlative' => 10,
        'issue_date' => '2026-10-01',
        'issue_time' => '10:00:00',
        'transfer_date' => '2026-10-02',
        'transport_mode' => '01',
        'transfer_reason' => '01',
        'total_weight' => 50.0,
        'weight_unit' => 'KGM',
        'packages_count' => 5,
        'origin_ubigeo' => '150101',
        'origin_address' => 'Av. Origen 123',
        'destination_ubigeo' => '150102',
        'destination_address' => 'Av. Destino 456',
        'recipient_doc_type' => '6',
        'recipient_doc_number' => '20123456789',
        'recipient_name' => 'DESTINATARIO TEST A',
        'status' => 'accepted',
    ]);

    // User B tries to view User A's document
    $this->actingAs($userB, 'sanctum')
        ->getJson("/api/v1/documents/{$documentA->id}")
        ->assertStatus(403);

    // User B tries to void User A's document
    $this->actingAs($userB, 'sanctum')
        ->postJson("/api/v1/documents/{$documentA->id}/void", ['reason' => 'Intento no autorizado'])
        ->assertStatus(403);

    // User B tries to view User A's despatch
    $this->actingAs($userB, 'sanctum')
        ->getJson("/api/v1/despatches/{$despatchA->id}")
        ->assertStatus(403);

    // User B tries to void User A's despatch
    $this->actingAs($userB, 'sanctum')
        ->postJson("/api/v1/despatches/{$despatchA->id}/void", ['reason' => 'Intento no autorizado'])
        ->assertStatus(403);

    // User B tries to emit invoice using User A's company_id
    $this->actingAs($userB, 'sanctum')
        ->postJson('/api/v1/invoices', [
            'company_id' => $companyA->id,
            'series' => 'F001',
            'correlative' => 999,
            'issue_date' => '2026-10-01',
            'issue_time' => '10:00:00',
            'currency' => 'PEN',
            'client' => [
                'doc_type' => '6',
                'doc_number' => '20123456789',
                'name' => 'TEST',
            ],
            'items' => [
                [
                    'description' => 'Test',
                    'unit_code' => 'NIU',
                    'quantity' => 1,
                    'unit_value' => 10,
                    'unit_price' => 11.8,
                    'igv_type' => '10',
                    'igv_amount' => 1.8,
                    'total' => 11.8,
                ],
            ],
            'totals' => [
                'taxable' => 10,
                'igv' => 1.8,
                'total' => 11.8,
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['company_id']);
});
