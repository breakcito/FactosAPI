<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PurchaseDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = PurchaseDocument::query()->with('company');

        if (!$user->isSuperAdmin()) {
            $userCompanyIds = $user->companies()->pluck('id');
            $query->whereIn('company_id', $userCompanyIds);
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        if ($request->filled('company_ids')) {
            $cIds = is_array($request->query('company_ids')) ? $request->query('company_ids') : explode(',', (string) $request->query('company_ids'));
            $query->whereIn('company_id', $cIds);
        }

        if ($request->filled('supplier_doc_number')) {
            $query->where('supplier_doc_number', $request->query('supplier_doc_number'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('supplier_name', 'like', "%{$search}%")
                    ->orWhere('supplier_doc_number', 'like', "%{$search}%")
                    ->orWhere('series', 'like', "%{$search}%")
                    ->orWhere('correlative', 'like', "%{$search}%")
                    ->orWhere('goods_description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('issue_date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('issue_date', '<=', $request->query('date_to'));
        }

        $purchases = $query->orderByDesc('issue_date')->paginate($request->integer('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data' => $purchases,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Soporta registro individual o por lotes (batch)
        $isBatch = $request->has('purchases') && is_array($request->input('purchases'));
        $items = $isBatch ? $request->input('purchases') : [$request->all()];

        $created = [];
        foreach ($items as $item) {
            $company = Company::findOrFail($item['company_id']);
            abort_if(!$user->isSuperAdmin() && $company->user_id !== $user->id, 403, 'No tiene autorización para registrar compras en esta empresa.');

            $purchase = PurchaseDocument::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'supplier_doc_number' => $item['supplier_doc_number'],
                    'type_code' => $item['type_code'] ?? '01',
                    'series' => strtoupper($item['series']),
                    'correlative' => (string) $item['correlative'],
                ],
                [
                    'supplier_doc_type' => $item['supplier_doc_type'] ?? '6',
                    'supplier_name' => $item['supplier_name'],
                    'issue_date' => $item['issue_date'],
                    'due_date' => $item['due_date'] ?? null,
                    'currency' => $item['currency'] ?? 'PEN',
                    'exchange_rate' => $item['exchange_rate'] ?? null,
                    'taxable_base' => $item['taxable_base'] ?? ($item['taxable'] ?? 0.00),
                    'igv' => $item['igv'] ?? 0.00,
                    'unaffected' => $item['unaffected'] ?? 0.00,
                    'exonerated' => $item['exonerated'] ?? 0.00,
                    'other_taxes' => $item['other_taxes'] ?? 0.00,
                    'total' => $item['total'],
                    'goods_description' => $item['goods_description'] ?? null,
                    'items' => $item['items'] ?? null,
                    'observations' => $item['observations'] ?? null,
                ]
            );

            $created[] = $purchase;
        }

        return response()->json([
            'status' => 'success',
            'message' => count($created) . ' comprobante(s) de compra registrado(s) correctamente.',
            'data' => $isBatch ? $created : $created[0],
        ], 201);
    }

    public function show(Request $request, PurchaseDocument $purchase): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $purchase->company->user_id !== $user->id, 403, 'No tiene autorización para consultar este comprobante.');

        $purchase->loadMissing('company');

        return response()->json([
            'status' => 'success',
            'data' => $purchase,
        ]);
    }

    public function destroy(Request $request, PurchaseDocument $purchase): JsonResponse
    {
        $user = $request->user();
        abort_if(!$user->isSuperAdmin() && $purchase->company->user_id !== $user->id, 403, 'No tiene autorización para eliminar este comprobante.');

        $purchase->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Comprobante de compra eliminado correctamente.',
        ]);
    }
}
