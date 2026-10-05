<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('user can register and list companies', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    $certificate = UploadedFile::fake()->create('certificate.pem', 200, 'text/plain');

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
        'ruc' => '20600055231',
        'business_name' => 'SERVICIOS TECNOLOGICOS S.A.C.',
        'trademark_name' => 'SERVITEC',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate' => $certificate,
        'certificate_pass' => '123456',
        'webhook_url' => 'https://example.com/webhook',
        'webhook_secret' => 'whsec_123',
        'is_production' => false,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'ruc' => '20600055231',
                'business_name' => 'SERVICIOS TECNOLOGICOS S.A.C.',
            ],
        ]);

    $this->assertDatabaseHas('companies', ['ruc' => '20600055231']);

    $listResponse = $this->actingAs($user, 'sanctum')->getJson('/api/v1/companies');
    $listResponse->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

test('user can show and update company settings', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id]);

    $showResponse = $this->actingAs($user, 'sanctum')->getJson("/api/v1/companies/{$company->id}");
    $showResponse->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $company->id,
                'ruc' => $company->ruc,
            ],
        ]);

    $updateResponse = $this->actingAs($user, 'sanctum')->putJson("/api/v1/companies/{$company->id}", [
        'email_notifications_active' => true,
        'send_to_client_email' => true,
        'company_copy_emails' => ['conta@empresa.pe'],
    ]);

    $updateResponse->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'email_notifications_active' => true,
                'send_to_client_email' => true,
            ],
        ]);

    expect($company->fresh()->email_notifications_active)->toBeTrue();
});

test('sensitive credentials are hidden from company json response', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create([
        'user_id' => $user->id,
        'sol_pass' => 'super_secret_sol_password',
        'certificate_pass' => 'super_secret_cert_password',
        'webhook_secret' => 'whsec_secret_key',
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/companies/{$company->id}");

    $response->assertStatus(200)
        ->assertJsonMissing(['sol_pass'])
        ->assertJsonMissing(['certificate_pass'])
        ->assertJsonMissing(['webhook_secret']);
});

test('user cannot view or update another user company', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $companyA = Company::factory()->create(['user_id' => $userA->id]);

    // User B tries to view User A's company
    $showResponse = $this->actingAs($userB, 'sanctum')->getJson("/api/v1/companies/{$companyA->id}");
    $showResponse->assertStatus(403);

    // User B tries to update User A's company
    $updateResponse = $this->actingAs($userB, 'sanctum')->putJson("/api/v1/companies/{$companyA->id}", [
        'business_name' => 'HACKED NAME',
    ]);
    $updateResponse->assertStatus(403);

    // User B tries to view User A's company webhooks
    $webhooksResponse = $this->actingAs($userB, 'sanctum')->getJson("/api/v1/companies/{$companyA->id}/webhooks");
    $webhooksResponse->assertStatus(403);
});

test('can register company with RUC starting with 15 or 17', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
        'ruc' => '15600055231',
        'business_name' => 'EXTRANJERO NEGOCIO E.I.R.L.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => '/cert.pem',
        'certificate_pass' => '123456',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.ruc', '15600055231');

    $this->assertDatabaseHas('companies', ['ruc' => '15600055231']);
});

test('company registration requires certificate or certificate_path', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
        'ruc' => '20600055231',
        'business_name' => 'EMPRESA SIN CERTIFICADO S.A.C.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['certificate']);
});

test('company can be registered and updated with gre api credentials and establishment code', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $certificate = UploadedFile::fake()->create('certificate.pem', 200, 'text/plain');

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
        'ruc' => '20600055232',
        'business_name' => 'EMPRESA GRE S.A.C.',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate' => $certificate,
        'certificate_pass' => '123456',
        'establishment_code' => '0001',
        'client_id' => 'gre-client-id-123',
        'client_secret' => 'gre-secret-xyz',
    ]);

    $response->assertStatus(201);
    $company = Company::where('ruc', '20600055232')->first();
    expect($company->establishment_code)->toBe('0001');
    expect($company->client_id)->toBe('gre-client-id-123');
    expect($company->client_secret)->toBe('gre-secret-xyz');

    $this->actingAs($user, 'sanctum')->putJson("/api/v1/companies/{$company->id}", [
        'establishment_code' => '0002',
    ])->assertStatus(200);

    expect($company->fresh()->establishment_code)->toBe('0002');
});
