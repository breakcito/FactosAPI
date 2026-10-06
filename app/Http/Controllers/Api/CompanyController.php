<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Company::query()->with('user:id,name,email')->withCount(['documents', 'webhookDeliveries']);

        if (!$user->isSuperAdmin()) {
            $query->where('user_id', $user->id);
        } else {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->query('user_id'));
            }
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('ruc', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('trademark_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_production')) {
            $query->where('is_production', filter_var($request->query('is_production'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $companies = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $companies,
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user->isSuperAdmin() && !empty($data['user_id'])) {
            $data['user_id'] = $data['user_id'];
        } else {
            $data['user_id'] = $user->id;
        }

        if ($request->hasFile('certificate')) {
            $file = $request->file('certificate');
            $extension = $file->getClientOriginalExtension() ?: 'pem';
            $filename = "cert.{$extension}";
            $path = $file->storeAs("tenants/{$data['ruc']}/certificates", $filename, config('factos.storage_disk', 'local'));
            $data['certificate_path'] = $path;
        }

        unset($data['certificate']);

        $company = Company::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Empresa emisora registrada correctamente.',
            'data' => $company->load('user:id,name,email'),
        ], 201);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $company->user_id !== $user->id, 403, 'No tiene autorización para acceder a esta empresa.');

        return response()->json([
            'status' => 'success',
            'data' => $company->load(['user:id,name,email'])->loadCount(['documents', 'webhookDeliveries']),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $company->user_id !== $user->id, 403, 'No tiene autorización para modificar esta empresa.');

        $data = $request->validated();

        if (!$user->isSuperAdmin()) {
            unset($data['user_id']);
        }

        if ($request->hasFile('certificate')) {
            $file = $request->file('certificate');
            $extension = $file->getClientOriginalExtension() ?: 'pem';
            $filename = "cert.{$extension}";
            $path = $file->storeAs("tenants/{$company->ruc}/certificates", $filename, config('factos.storage_disk', 'local'));
            $data['certificate_path'] = $path;
        }

        unset($data['certificate']);

        $company->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Empresa actualizada correctamente.',
            'data' => $company->fresh()->load('user:id,name,email'),
        ]);
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $company->user_id !== $user->id, 403, 'No tiene autorización para eliminar esta empresa.');

        if ($company->documents()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la empresa porque tiene comprobantes emitidos. Puedes desactivarla en su lugar.',
            ], 422);
        }

        $company->webhookDeliveries()->delete();
        $company->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Empresa eliminada correctamente.',
        ]);
    }

    public function webhooks(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $company->user_id !== $user->id, 403, 'No tiene autorización para acceder a los webhooks de esta empresa.');

        $deliveries = $company->webhookDeliveries()
            ->latest()
            ->paginate(25);

        return response()->json([
            'status' => 'success',
            'data' => $deliveries,
        ]);
    }
}
