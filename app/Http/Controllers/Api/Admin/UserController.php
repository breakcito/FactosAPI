<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->withCount(['companies']);

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->has('is_active') && $request->query('is_active') !== '') {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $users = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', 'string', Rule::in(['superadmin', 'developer', 'admin'])],
            'is_active' => ['nullable', 'boolean'],
            'create_test_company' => ['nullable', 'boolean'],
        ]);

        $shouldCreateTestCompany = $request->has('create_test_company')
            ? filter_var($request->input('create_test_company'), FILTER_VALIDATE_BOOLEAN)
            : true;

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? 'developer',
            'is_active' => $data['is_active'] ?? true,
        ]);

        // Generate automatic permanent API key for POS/ERP integration
        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'API Key Inicial (' . $user->name . ')',
            'key' => ApiKey::generateKey(),
            'is_active' => true,
        ]);

        // Provision SUNAT Beta test company if requested (active by default)
        $testCompany = null;
        if ($shouldCreateTestCompany) {
            $testCompany = Company::createTestCompanyForUser($user);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario registrado exitosamente con API Key generada' . ($testCompany ? ' y empresa de prueba asociada.' : '.'),
            'data' => [
                'user' => $user->loadCount('companies'),
                'api_key' => $apiKey->key,
                'test_company' => $testCompany,
            ],
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $user->load(['companies']),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'required', 'string', Rule::in(['superadmin', 'developer', 'admin'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        // Prevent superadmin from deactivating or demoting themselves
        if ($user->id === $request->user()->id) {
            if (isset($data['is_active']) && !$data['is_active']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No puedes desactivar tu propia cuenta de super usuario.',
                ], 422);
            }
            if (isset($data['role']) && $data['role'] !== 'superadmin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No puedes cambiar el rol de tu propia cuenta de super usuario.',
                ], 422);
            }
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario actualizado exitosamente.',
            'data' => $user->fresh()->loadCount('companies'),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No puedes eliminar tu propia cuenta.',
            ], 422);
        }

        if ($user->companies()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el usuario porque tiene empresas registradas. Reasigna o elimina sus empresas primero.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario eliminado correctamente.',
        ]);
    }

    public function createToken(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'token_name' => ['nullable', 'string', 'max:255'],
        ]);

        $tokenName = $data['token_name'] ?? 'Api Token - ' . now()->toIso8601String();
        $token = $user->createToken($tokenName);

        return response()->json([
            'status' => 'success',
            'message' => 'Token API generado correctamente para el usuario.',
            'token' => $token->plainTextToken,
        ]);
    }
}
