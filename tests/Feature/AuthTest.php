<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public registration is disabled and returns 404', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Factos Dev',
        'email' => 'dev@factos.pe',
        'password' => 'secret12345!',
    ]);

    $response->assertStatus(404);
});

test('user can login and obtain bearer token', function () {
    $user = User::factory()->create([
        'email' => 'login@factos.pe',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'login@factos.pe',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'email'],
        ]);
});

test('authenticated user can view profile and logout', function () {
    $user = User::factory()->create();

    $meResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me');

    $meResponse->assertStatus(200)
        ->assertJson([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);

    $logoutResponse = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/logout');

    $logoutResponse->assertStatus(200);
});
