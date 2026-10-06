<?php

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('registering a user automatically generates an API key and default test company', function () {
    $admin = User::factory()->create([
        'role' => 'superadmin',
        'is_active' => true,
    ]);

    // Create a template test company in DB
    $template = Company::create([
        'user_id' => $admin->id,
        'ruc' => '20000000001',
        'business_name' => 'EMPRESA DE PRUEBA SUNAT S.A.C.',
        'trademark_name' => 'FACTOS BETA TEST',
        'sol_user' => 'MODDATOS',
        'sol_pass' => 'moddatos',
        'certificate_path' => 'cert.pem',
        'certificate_pass' => '123456',
        'is_production' => false,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
        'name' => 'Nuevo Dev',
        'email' => 'nuevodev@test.pe',
        'password' => 'secret12345',
        'role' => 'developer',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email'],
                'api_key',
                'test_company' => ['id', 'ruc', 'user_id'],
            ],
        ]);

    $newUser = User::where('email', 'nuevodev@test.pe')->firstOrFail();

    // Verify automatic API key
    $apiKey = ApiKey::where('user_id', $newUser->id)->first();
    expect($apiKey)->not->toBeNull()
        ->and($apiKey->is_active)->toBeTrue()
        ->and($apiKey->key)->toStartWith('factos_live_');

    // Verify automatic test company
    $testCompany = Company::where('user_id', $newUser->id)->where('is_production', false)->first();
    expect($testCompany)->not->toBeNull()
        ->and($testCompany->ruc)->toBe('20000000001')
        ->and($testCompany->id)->not->toBe($template->id)
        ->and($testCompany->user_id)->toBe($newUser->id);
});

test('registering a user with create_test_company = false skips test company creation', function () {
    $admin = User::factory()->create([
        'role' => 'superadmin',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
        'name' => 'Dev Sin Empresa',
        'email' => 'devsinempresa@test.pe',
        'password' => 'secret12345',
        'role' => 'developer',
        'create_test_company' => false,
    ]);

    $response->assertStatus(201);

    $newUser = User::where('email', 'devsinempresa@test.pe')->firstOrFail();

    // API key was created
    expect(ApiKey::where('user_id', $newUser->id)->exists())->toBeTrue();

    // Test company was NOT created
    expect(Company::where('user_id', $newUser->id)->exists())->toBeFalse();
});

test('developer can create test company manually via post /companies/test-company', function () {
    $dev = User::factory()->create([
        'role' => 'developer',
        'is_active' => true,
    ]);

    // Initially has no company
    expect(Company::where('user_id', $dev->id)->exists())->toBeFalse();

    // 1. Create test company manually
    $response = $this->actingAs($dev)->postJson('/api/v1/companies/test-company');

    $response->assertStatus(201)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'user_id' => $dev->id,
                'is_production' => false,
            ],
        ]);

    $company = Company::where('user_id', $dev->id)->first();
    expect($company)->not->toBeNull()
        ->and($company->is_production)->toBeFalse();

    // 2. Calling again returns idempotent info message
    $response2 = $this->actingAs($dev)->postJson('/api/v1/companies/test-company');
    $response2->assertStatus(200)
        ->assertJson([
            'status' => 'info',
        ]);
});
