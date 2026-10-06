<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Despatch;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = $user->isSuperAdmin();

        if ($isSuperAdmin) {
            // Superadmin: Global statistics across all tenants and users
            $companiesCount = Company::query()->count();
            $activeCompaniesCount = Company::query()->where('is_active', true)->count();
            $productionCompaniesCount = Company::query()->where('is_production', true)->count();
            $betaCompaniesCount = Company::query()->where('is_production', false)->count();

            $documentsCount = Document::query()->count();
            $acceptedDocsCount = Document::query()->where('status', 'accepted')->count();
            $rejectedDocsCount = Document::query()->where('status', 'rejected')->count();
            $pendingDocsCount = Document::query()->whereIn('status', ['pending', 'waiting_sunat', 'processing'])->count();
            $voidedDocsCount = Document::query()->where('status', 'voided')->count();

            $totalPen = Document::query()->where('currency', 'PEN')->where('status', 'accepted')->sum('total');
            $totalUsd = Document::query()->where('currency', 'USD')->where('status', 'accepted')->sum('total');

            $despatchesCount = Despatch::query()->count();
            $acceptedDespatchesCount = Despatch::query()->where('status', 'accepted')->count();

            $usersCount = User::query()->count();
            $developersCount = User::query()->where('role', 'developer')->count();

            $apiKeysCount = ApiKey::query()->count();
            $activeApiKeysCount = ApiKey::query()->where('is_active', true)->count();

            $recentDocuments = Document::query()
                ->with('company:id,business_name,ruc')
                ->latest('issue_date')
                ->latest('created_at')
                ->limit(8)
                ->get();

            $recentCompanies = Company::query()
                ->with('user:id,name,email')
                ->withCount('documents')
                ->latest()
                ->limit(5)
                ->get();
        } else {
            // Developer: strictly scoped to developer's companies, documents and API keys
            $userCompanyIds = $user->companies()->pluck('id');

            $companiesCount = $user->companies()->count();
            $activeCompaniesCount = $user->companies()->where('is_active', true)->count();
            $productionCompaniesCount = $user->companies()->where('is_production', true)->count();
            $betaCompaniesCount = $user->companies()->where('is_production', false)->count();

            $documentsCount = Document::query()->whereIn('company_id', $userCompanyIds)->count();
            $acceptedDocsCount = Document::query()->whereIn('company_id', $userCompanyIds)->where('status', 'accepted')->count();
            $rejectedDocsCount = Document::query()->whereIn('company_id', $userCompanyIds)->where('status', 'rejected')->count();
            $pendingDocsCount = Document::query()->whereIn('company_id', $userCompanyIds)->whereIn('status', ['pending', 'waiting_sunat', 'processing'])->count();
            $voidedDocsCount = Document::query()->whereIn('company_id', $userCompanyIds)->where('status', 'voided')->count();

            $totalPen = Document::query()->whereIn('company_id', $userCompanyIds)->where('currency', 'PEN')->where('status', 'accepted')->sum('total');
            $totalUsd = Document::query()->whereIn('company_id', $userCompanyIds)->where('currency', 'USD')->where('status', 'accepted')->sum('total');

            $despatchesCount = Despatch::query()->whereIn('company_id', $userCompanyIds)->count();
            $acceptedDespatchesCount = Despatch::query()->whereIn('company_id', $userCompanyIds)->where('status', 'accepted')->count();

            $usersCount = 1;
            $developersCount = 1;

            $apiKeysCount = $user->apiKeys()->count();
            $activeApiKeysCount = $user->apiKeys()->where('is_active', true)->count();

            $recentDocuments = Document::query()
                ->whereIn('company_id', $userCompanyIds)
                ->with('company:id,business_name,ruc')
                ->latest('issue_date')
                ->latest('created_at')
                ->limit(8)
                ->get();

            $recentCompanies = $user->companies()
                ->withCount('documents')
                ->latest()
                ->limit(5)
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'is_superadmin' => $isSuperAdmin,
                'role' => $user->role,
                'companies' => [
                    'total' => $companiesCount,
                    'active' => $activeCompaniesCount,
                    'production' => $productionCompaniesCount,
                    'beta' => $betaCompaniesCount,
                ],
                'documents' => [
                    'total' => $documentsCount,
                    'accepted' => $acceptedDocsCount,
                    'rejected' => $rejectedDocsCount,
                    'pending' => $pendingDocsCount,
                    'voided' => $voidedDocsCount,
                    'total_pen' => (float) $totalPen,
                    'total_usd' => (float) $totalUsd,
                ],
                'despatches' => [
                    'total' => $despatchesCount,
                    'accepted' => $acceptedDespatchesCount,
                ],
                'users' => [
                    'total' => $usersCount,
                    'developers' => $developersCount,
                ],
                'api_keys' => [
                    'total' => $apiKeysCount,
                    'active' => $activeApiKeysCount,
                ],
                'recent_documents' => $recentDocuments,
                'recent_companies' => $recentCompanies,
            ],
        ]);
    }
}
