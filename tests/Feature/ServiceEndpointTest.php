<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('services endpoint resolves DNI', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://dniruc.apisperu.com/api/v1/dni/*' => Http::response([
            'success' => true,
            'dni' => '72728282',
            'nombres' => 'GIANFRANCO ALESSANDRO',
            'apellidoPaterno' => 'SULLCA',
            'apellidoMaterno' => 'ORTIZ',
            'codVerifica' => 9,
        ], 200),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/services/dni/72728282');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'dni' => '72728282',
                'nombres' => 'GIANFRANCO ALESSANDRO',
                'apellido_paterno' => 'SULLCA',
                'apellido_materno' => 'ORTIZ',
                'codigo_verificacion' => '9',
            ],
        ]);
});

test('services endpoint resolves RUC', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://dniruc.apisperu.com/api/v1/ruc/*' => Http::response([
            'ruc' => '20131312955',
            'razonSocial' => 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT',
            'estado' => 'ACTIVO',
            'condicion' => 'HABIDO',
            'direccion' => 'AV. GARCILASO DE LA VEGA NRO. 1472',
            'ubigeo' => '150101',
        ], 200),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/services/ruc/20131312955');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'ruc' => '20131312955',
                'razon_social' => 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT',
                'estado' => 'ACTIVO',
                'condicion' => 'HABIDO',
                'ubigeo' => '150101',
            ],
        ]);
});

test('services endpoint resolves exchange rate', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://tipocambio.apisperu.com/api/v1/sunat*' => Http::response([
            'success' => true,
            'date' => '2026-09-25',
            'source' => 'SUNAT',
            'rates' => [
                'USD' => [
                    'name' => 'Dólar de N.A.',
                    'buy' => '3.3800',
                    'sell' => '3.3850',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/services/exchange-rate?source=sunat&date=2026-09-25');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'date' => '2026-09-25',
                'source' => 'SUNAT',
                'currency' => 'USD',
                'compra' => 3.38,
                'venta' => 3.385,
            ],
        ]);
});

test('services endpoint resolves SBS exchange rate', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://tipocambio.apisperu.com/api/v1/sbs*' => Http::response([
            'success' => true,
            'date' => '2026-09-25',
            'source' => 'SBS',
            'rates' => [
                'USD' => [
                    'name' => 'Dólar de N.A.',
                    'buy' => '3.3750',
                    'sell' => '3.3820',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/services/exchange-rate?source=sbs&date=2026-09-25');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'date' => '2026-09-25',
                'source' => 'SBS',
                'currency' => 'USD',
                'compra' => 3.375,
                'venta' => 3.382,
            ],
        ]);
});

test('services endpoint accepts RUC starting with 15 and 17', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://dniruc.apisperu.com/api/v1/ruc/15123456789*' => Http::response([
            'ruc' => '15123456789',
            'razonSocial' => 'EXTRANJERO DOMICILIADO 15',
            'estado' => 'ACTIVO',
            'condicion' => 'HABIDO',
            'direccion' => 'AV. MIRAFLORES 123',
            'ubigeo' => '150122',
        ], 200),
        'https://dniruc.apisperu.com/api/v1/ruc/17123456789*' => Http::response([
            'ruc' => '17123456789',
            'razonSocial' => 'EXTRANJERO DOMICILIADO 17',
            'estado' => 'ACTIVO',
            'condicion' => 'HABIDO',
            'direccion' => 'AV. SAN ISIDRO 456',
            'ubigeo' => '150131',
        ], 200),
    ]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/services/ruc/15123456789')
        ->assertStatus(200)
        ->assertJsonPath('data.ruc', '15123456789');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/services/ruc/17123456789')
        ->assertStatus(200)
        ->assertJsonPath('data.ruc', '17123456789');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/services/ruc/99123456789')
        ->assertStatus(422);
});
