<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class JwtService
{
    protected string $secret;

    protected string $algo;

    public function __construct()
    {
        $this->secret = (string) (config('jwt.secret') ?: config('app.key'));
        $this->algo = (string) (config('jwt.algo') ?: 'HS256');
    }

    /**
     * Generate a non-expiring JWT token for a given user.
     */
    public function generateTokenForUser(User $user): string
    {
        $payload = [
            'iss' => config('app.name', 'Factos API'),
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'iat' => time(),
            // No 'exp' claim is set so the session does not expire
        ];

        return JWT::encode($payload, $this->secret, $this->algo);
    }

    /**
     * Decode and verify a JWT token. Returns null if invalid or tampered.
     */
    public function decode(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (Throwable) {
            return null;
        }
    }
}
