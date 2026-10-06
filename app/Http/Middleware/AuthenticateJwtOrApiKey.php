<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwtOrApiKey
{
    public function __construct(
        protected JwtService $jwtService
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();

        if (!$rawToken) {
            $rawToken = $request->header('X-API-KEY') ?: $request->header('x-api-key');
        }

        if (!$rawToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado. Proporcione un token JWT o API Key en la cabecera Authorization o X-API-KEY.',
            ], 401);
        }

        // 1. Check if it's an API Key (starts with factos_ or exists in api_keys table)
        if (str_starts_with($rawToken, 'factos_') || !str_contains($rawToken, '.')) {
            $apiKey = ApiKey::query()
                ->where('key', $rawToken)
                ->where('is_active', true)
                ->with('user')
                ->first();

            if ($apiKey && $apiKey->user && $apiKey->user->is_active && !$apiKey->user->trashed()) {
                $apiKey->forceFill(['last_used_at' => now()])->save();
                $user = $apiKey->user;

                $request->setUserResolver(fn() => $user);
                Auth::setUser($user);

                return $next($request);
            }
        }

        // 2. Check if it's a valid JWT
        if (substr_count($rawToken, '.') === 2) {
            $payload = $this->jwtService->decode($rawToken);

            if ($payload && isset($payload->sub)) {
                $user = User::query()->find($payload->sub);

                if ($user && $user->is_active && !$user->trashed()) {
                    $request->setUserResolver(fn() => $user);
                    Auth::setUser($user);

                    return $next($request);
                }
            }
        }

        // 3. Fallback: Check if it's a PersonalAccessToken (Sanctum)
        $accessToken = PersonalAccessToken::findToken($rawToken);
        if ($accessToken && $accessToken->tokenable instanceof User) {
            $user = $accessToken->tokenable;
            if ($user && $user->is_active && !$user->trashed()) {
                $request->setUserResolver(fn() => $user);
                Auth::setUser($user);

                return $next($request);
            }
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Token JWT o API Key inválido, inactivo o revocado.',
        ], 401);
    }
}
