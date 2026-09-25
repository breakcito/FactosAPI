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
        $companies = Company::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $companies,
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

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
            'data' => $company,
        ], 201);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $company,
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

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
            'data' => $company->fresh(),
        ]);
    }

    public function webhooks(Company $company): JsonResponse
    {
        $deliveries = $company->webhookDeliveries()
            ->latest()
            ->paginate(25);

        return response()->json([
            'status' => 'success',
            'data' => $deliveries,
        ]);
    }
}
