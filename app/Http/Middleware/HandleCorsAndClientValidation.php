<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCorsAndClientValidation
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $corsEnabled = filter_var(config('cors.enabled', env('ENABLED_CORS', false)), FILTER_VALIDATE_BOOLEAN);
        $clientUrlConfig = config('cors.client_url', env('APP_CLIENT_URL', 'http://localhost:5173'));
        $origin = $request->header('Origin');

        // 1. If CORS is disabled (e.g. testing in local): allow all origins
        if (!$corsEnabled) {
            if ($request->isMethod('OPTIONS')) {
                return $this->buildPreflightResponse($origin ?: '*');
            }

            $response = $next($request);

            return $this->addCorsHeaders($response, $origin ?: '*');
        }

        // 2. If CORS is enabled (e.g. production):
        // If there is no Origin header (e.g. server-to-server curl, backend ERP, desktop POS)
        if (!$origin) {
            return $next($request);
        }

        $allowedClients = array_values(array_filter(array_map(
            fn ($url) => rtrim(strtolower(trim($url)), '/'),
            explode(',', (string) $clientUrlConfig)
        )));

        $normalizedOrigin = rtrim(strtolower(trim($origin)), '/');
        $isExpectedClient = in_array($normalizedOrigin, $allowedClients, true);

        // A) If the request comes from the expected client frontend:
        if ($isExpectedClient) {
            if ($request->isMethod('OPTIONS')) {
                return $this->buildPreflightResponse($origin);
            }

            $response = $next($request);

            return $this->addCorsHeaders($response, $origin);
        }

        // B) If it's NOT the expected client:
        // Preflight OPTIONS: allow preflight so the browser can send the subsequent request with API Key
        if ($request->isMethod('OPTIONS')) {
            return $this->buildPreflightResponse($origin);
        }

        // Check if an API Key is provided
        $apiKeyString = $request->header('X-API-KEY') ?: $request->header('x-api-key');
        $bearerToken = $request->bearerToken();
        if (!$apiKeyString && $bearerToken && str_starts_with($bearerToken, 'factos_live_')) {
            $apiKeyString = $bearerToken;
        }

        if (!$apiKeyString) {
            return response()->json([
                'status' => 'error',
                'message' => "Acceso denegado por CORS: El origen '{$origin}' no está autorizado. Para interactuar desde un origen externo debe enviar una API Key legítima en X-API-KEY o Authorization.",
            ], 403);
        }

        // Validate the API Key against the database
        $apiKey = ApiKey::query()
            ->where('key', $apiKeyString)
            ->where('is_active', true)
            ->with('user')
            ->first();

        $isLegitKey = $apiKey && $apiKey->user && $apiKey->user->is_active && !$apiKey->user->trashed();

        if (!$isLegitKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Acceso denegado: La API Key proporcionada es inválida, inactiva o fue revocada.',
            ], 401);
        }

        // Legit API Key from external origin: allow request and set CORS headers
        $response = $next($request);

        return $this->addCorsHeaders($response, $origin);
    }

    /**
     * Build preflight response.
     */
    protected function buildPreflightResponse(string $allowedOrigin): Response
    {
        return response('', 204, [
            'Access-Control-Allow-Origin' => $allowedOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-API-KEY, x-api-key, Accept, X-Requested-With, Origin',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ]);
    }

    /**
     * Add standard CORS headers to response.
     */
    protected function addCorsHeaders(Response $response, string $allowedOrigin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-KEY, x-api-key, Accept, X-Requested-With, Origin');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
