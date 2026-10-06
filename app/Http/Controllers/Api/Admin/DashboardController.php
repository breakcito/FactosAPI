<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'status' => 'success',
            'data' => [
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
                'recent_documents' => $recentDocuments,
                'recent_companies' => $recentCompanies,
            ],
        ]);
    }
}
