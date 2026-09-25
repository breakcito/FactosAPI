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
