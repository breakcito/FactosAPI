<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ApiKey::query()->with('user:id,name,email');

        if (!$user->isSuperAdmin()) {
            $query->where('user_id', $user->id);
        } else {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->query('user_id'));
            }
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $apiKeys = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $apiKeys,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'user_id' => ['nullable', 'exists:users,id'],
        ]);

        $ownerId = ($user->isSuperAdmin() && !empty($data['user_id']))
            ? (int) $data['user_id']
            : $user->id;

        $keyString = ApiKey::generateKey('factos_live_');

        $apiKey = ApiKey::create([
            'user_id' => $ownerId,
            'name' => $data['name'],
            'key' => $keyString,
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'API Key generada exitosamente. Esta clave no expira a menos que la regenere.',
            'data' => $apiKey->load('user:id,name,email'),
        ], 201);
    }

    public function update(Request $request, ApiKey $apiKey): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $apiKey->user_id !== $user->id, 403, 'No tiene autorización para modificar esta API Key.');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $apiKey->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'API Key actualizada correctamente.',
            'data' => $apiKey->fresh()->load('user:id,name,email'),
        ]);
    }

    public function regenerate(Request $request, ApiKey $apiKey): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $apiKey->user_id !== $user->id, 403, 'No tiene autorización para regenerar esta API Key.');

        $newKey = ApiKey::generateKey('factos_live_');
        $apiKey->update([
            'key' => $newKey,
            'last_used_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'API Key regenerada exitosamente.',
            'data' => $apiKey->fresh()->load('user:id,name,email'),
        ]);
    }

    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $apiKey->user_id !== $user->id, 403, 'No tiene autorización para eliminar esta API Key.');

        // Logical / Soft delete only
        $apiKey->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'API Key eliminada lógicamente (revocada).',
        ]);
    }
}
