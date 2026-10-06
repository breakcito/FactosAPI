<?php

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('superadmin can access dashboard and view global system statistics', function () {
    $admin = User::factory()->create([
        'role' => 'superadmin',
        'is_active' => true,
    ]);

    $dev = User::factory()->create([
        'role' => 'developer',
        'is_active' => true,
    ]);

    $adminCompany = Company::create([
        'user_id' => $admin->id,
        'ruc' => '20123456789',
        'business_name' => 'ADMIN CORP SAC',
        'trademark_name' => 'ADMIN',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'cert.pem',
        'certificate_pass' => '123456',
        'is_production' => true,
        'is_active' => true,
    ]);

    $devCompany = Company::create([
        'user_id' => $dev->id,
        'ruc' => '20987654321',
        'business_name' => 'DEV CORP SAC',
        'trademark_name' => 'DEV',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'cert.pem',
        'certificate_pass' => '123456',
        'is_production' => false,
        'is_active' => true,
    ]);

    $token = app(JwtService::class)->generateTokenForUser($admin);

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/dashboard');

    $response->assertOk()
        ->assertJson([
            'status' => 'success',
            'data' => [
                'is_superadmin' => true,
                'role' => 'superadmin',
                'companies' => [
                    'total' => 2,
                    'production' => 1,
                    'beta' => 1,
                ],
                'users' => [
                    'total' => 2,
                    'developers' => 1,
                ],
            ],
        ]);

    // Also verify via /admin/dashboard
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/admin/dashboard')
        ->assertOk();
});

test('developer can access dashboard without 403 and receives scoped metrics', function () {
    $admin = User::factory()->create([
        'role' => 'superadmin',
        'is_active' => true,
    ]);

    $dev = User::factory()->create([
        'role' => 'developer',
        'is_active' => true,
    ]);

    // Admin company with a document
    $adminCompany = Company::create([
        'user_id' => $admin->id,
        'ruc' => '20123456789',
        'business_name' => 'ADMIN CORP SAC',
        'trademark_name' => 'ADMIN',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'cert.pem',
        'certificate_pass' => '123456',
        'is_production' => true,
        'is_active' => true,
    ]);

    Document::create([
        'company_id' => $adminCompany->id,
        'type_code' => '01',
        'series' => 'F001',
        'correlative' => '00000001',
        'status' => 'accepted',
        'currency' => 'PEN',
        'total' => 1000.00,
        'issue_date' => now()->toDateString(),
        'issue_time' => now()->toTimeString(),
        'client_doc_type' => '6',
        'client_doc_number' => '20444555666',
        'client_name' => 'CLIENTE ADMIN',
    ]);

    // Dev company with a document
    $devCompany = Company::create([
        'user_id' => $dev->id,
        'ruc' => '20987654321',
        'business_name' => 'DEV TEST SAC',
        'trademark_name' => 'DEV TEST',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'cert.pem',
        'certificate_pass' => '123456',
        'is_production' => false,
        'is_active' => true,
    ]);

    Document::create([
        'company_id' => $devCompany->id,
        'type_code' => '03',
        'series' => 'B001',
        'correlative' => '00000001',
        'status' => 'accepted',
        'currency' => 'PEN',
        'total' => 50.00,
        'issue_date' => now()->toDateString(),
        'issue_time' => now()->toTimeString(),
        'client_doc_type' => '1',
        'client_doc_number' => '77889900',
        'client_name' => 'CLIENTE DEV',
    ]);

    ApiKey::create([
        'user_id' => $dev->id,
        'name' => 'Default Test Key',
        'key' => 'factos_live_devkey123',
        'is_active' => true,
    ]);

    $token = app(JwtService::class)->generateTokenForUser($dev);

    // Both /api/v1/dashboard and /api/v1/admin/dashboard must work without 403
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/dashboard');

    $response->assertOk()
        ->assertJson([
            'status' => 'success',
            'data' => [
                'is_superadmin' => false,
                'role' => 'developer',
                'companies' => [
                    'total' => 1,
                    'production' => 0,
                    'beta' => 1,
                ],
                'documents' => [
                    'total' => 1,
                    'accepted' => 1,
                    'total_pen' => 50.00,
                ],
                'api_keys' => [
                    'total' => 1,
                    'active' => 1,
                ],
            ],
        ]);

    // Ensure recent documents only contains the developer's document
    $data = $response->json('data');
    expect($data['recent_documents'])->toHaveCount(1)
        ->and($data['recent_documents'][0]['series'])->toBe('B001');

    // Also verify /admin/dashboard alias
    $responseAlias = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/admin/dashboard');

    $responseAlias->assertOk()
        ->assertJsonPath('data.is_superadmin', false)
        ->assertJsonPath('data.role', 'developer');
});
