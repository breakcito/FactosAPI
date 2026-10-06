<?php

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

test('when ENABLED_CORS is false, any origin is allowed without restrictions', function () {
    Config::set('cors.enabled', false);
    Config::set('cors.client_url', 'http://localhost:5173');

    // 1. Preflight from untrusted origin
    $preflight = $this->withHeaders([
        'Origin' => 'https://random-frontend.com',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/health');

    $preflight->assertStatus(204)
        ->assertHeader('Access-Control-Allow-Origin', 'https://random-frontend.com');

    // 2. GET request from untrusted origin
    $response = $this->withHeaders([
        'Origin' => 'https://random-frontend.com',
    ])->get('/api/health');

    $response->assertStatus(200)
        ->assertHeader('Access-Control-Allow-Origin', 'https://random-frontend.com');
});

test('when ENABLED_CORS is true, APP_CLIENT_URL origin is allowed', function () {
    Config::set('cors.enabled', true);
    Config::set('cors.client_url', 'http://localhost:5173');

    // 1. Preflight from expected client
    $preflight = $this->withHeaders([
        'Origin' => 'http://localhost:5173',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/health');

    $preflight->assertStatus(204)
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');

    // 2. Request from expected client
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:5173',
    ])->get('/api/health');

    $response->assertStatus(200)
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
});

test('when ENABLED_CORS is true, unexpected origin WITHOUT an API key is denied with 403', function () {
    Config::set('cors.enabled', true);
    Config::set('cors.client_url', 'http://localhost:5173');

    $response = $this->withHeaders([
        'Origin' => 'https://malicious-site.com',
    ])->getJson('/api/health');

    $response->assertStatus(403)
        ->assertJson([
            'status' => 'error',
        ]);
});

test('when ENABLED_CORS is true, unexpected origin with an INVALID API key is denied with 401', function () {
    Config::set('cors.enabled', true);
    Config::set('cors.client_url', 'http://localhost:5173');

    $response = $this->withHeaders([
        'Origin' => 'https://external-pos-system.com',
        'X-API-KEY' => 'factos_live_invalidkey999999',
    ])->getJson('/api/health');

    $response->assertStatus(401)
        ->assertJson([
            'status' => 'error',
            'message' => 'Acceso denegado: La API Key proporcionada es inválida, inactiva o fue revocada.',
        ]);
});

test('when ENABLED_CORS is true, unexpected origin with a VALID API key is granted access', function () {
    Config::set('cors.enabled', true);
    Config::set('cors.client_url', 'http://localhost:5173');

    $user = User::factory()->create([
        'role' => 'developer',
        'is_active' => true,
    ]);

    $apiKey = ApiKey::create([
        'user_id' => $user->id,
        'name' => 'External POS Key',
        'key' => ApiKey::generateKey(),
        'is_active' => true,
    ]);

    // Request from external origin sending the valid API Key
    $response = $this->withHeaders([
        'Origin' => 'https://external-pos-system.com',
        'X-API-KEY' => $apiKey->key,
    ])->getJson('/api/health');

    $response->assertStatus(200)
        ->assertHeader('Access-Control-Allow-Origin', 'https://external-pos-system.com');
});
