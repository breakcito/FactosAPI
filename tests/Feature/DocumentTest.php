<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('user can consult document details', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);
    $document = Document::factory()->create([
        'company_id' => $company->id,
        'series' => 'F001',
        'correlative' => 452,
        'status' => 'accepted',
    ]);
    DocumentItem::factory()->create(['document_id' => $document->id]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/documents/{$document->id}");

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $document->id,
                'series' => 'F001',
                'correlative' => 452,
                'document_number' => 'F001-452',
                'status' => 'accepted',
            ],
        ])
        ->assertJsonStructure([
            'data' => [
                'links' => ['xml', 'cdr', 'pdf'],
                'items',
            ],
        ]);
});

test('document xml, cdr and pdf can be downloaded via link endpoints', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'ruc' => '20123456789']);

    $xmlPath = 'tenants/20123456789/2026/09/01-F001-452.xml';
    $cdrPath = 'tenants/20123456789/2026/09/R-01-F001-452.zip';
    $pdfPath = 'tenants/20123456789/2026/09/01-F001-452.pdf';

    Storage::disk('local')->put($xmlPath, '<Invoice>Test XML Content</Invoice>');
    Storage::disk('local')->put($cdrPath, 'ZIP-CDR-BINARY-CONTENT');
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 Test PDF Content');

    $document = Document::factory()->create([
        'company_id' => $company->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => 452,
        'xml_path' => $xmlPath,
        'cdr_path' => $cdrPath,
        'pdf_path' => $pdfPath,
        'status' => 'accepted',
    ]);

    // Download XML
    $xmlResponse = $this->get("/api/v1/documents/{$document->id}/xml");
    $xmlResponse->assertStatus(200)
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<Invoice>Test XML Content</Invoice>', false);

    // Download CDR
    $cdrResponse = $this->get("/api/v1/documents/{$document->id}/cdr");
    $cdrResponse->assertStatus(200)
        ->assertHeader('Content-Type', 'application/zip')
        ->assertSee('ZIP-CDR-BINARY-CONTENT', false);

    // Download PDF
    $pdfResponse = $this->get("/api/v1/documents/{$document->id}/pdf");
    $pdfResponse->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee('%PDF-1.4 Test PDF Content', false);
});

test('user cannot list, view or void documents from another tenant', function () {
    $userA = User::factory()->create();
    $companyA = Company::factory()->create(['user_id' => $userA->id]);
    $docA = Document::factory()->create([
        'company_id' => $companyA->id,
        'status' => 'accepted',
    ]);

    $userB = User::factory()->create();
    $companyB = Company::factory()->create(['user_id' => $userB->id]);
    $docB = Document::factory()->create([
        'company_id' => $companyB->id,
        'status' => 'accepted',
    ]);

    // User A only sees docA, not docB
    $indexResponse = $this->actingAs($userA, 'sanctum')->getJson('/api/v1/documents');
    $indexResponse->assertStatus(200);
    $data = $indexResponse->json('data.data');
    expect(count($data))->toBe(1);
    expect($data[0]['id'])->toBe($docA->id);

    // User A cannot show docB
    $showResponse = $this->actingAs($userA, 'sanctum')->getJson("/api/v1/documents/{$docB->id}");
    $showResponse->assertStatus(403);

    // User A cannot void docB via uuid endpoint
    $voidResponse = $this->actingAs($userA, 'sanctum')->postJson("/api/v1/documents/{$docB->id}/void", [
        'reason' => 'Anulacion no autorizada',
    ]);
    $voidResponse->assertStatus(403);

    // User A cannot void docB via general endpoint
    $voidGeneralResponse = $this->actingAs($userA, 'sanctum')->postJson('/api/v1/documents/void', [
        'company_id' => $companyB->id,
        'type_code' => $docB->type_code,
        'series' => $docB->series,
        'correlative' => $docB->correlative,
        'reason' => 'Anulacion no autorizada',
    ]);
    $voidGeneralResponse->assertStatus(404);
});
