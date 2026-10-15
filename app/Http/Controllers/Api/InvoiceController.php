<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\AppliedAdvance;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $series = strtoupper($validated['series']);
        $typeCode = $validated['type_code'] ?? $request->determineTypeCode();

        $company = Company::findOrFail($validated['company_id']);
        $wantsTest = (bool) ($validated['is_test'] ?? $validated['test_mode'] ?? (isset($validated['is_production']) ? !$validated['is_production'] : false));
        $isProduction = (bool) ($company->is_production && !$wantsTest);

        $totals = $validated['totals'];
        $client = $validated['client'];

        // Guardar información desnormalizada de la empresa emisora para inmutabilidad del comprobante
        $companyData = [
            'id' => $company->id,
            'ruc' => $company->ruc,
            'business_name' => $company->business_name,
            'trademark_name' => $company->trademark_name,
            'address' => $company->address,
            'ubigeo' => $company->ubigeo,
            'department' => $company->department,
            'province' => $company->province,
            'district' => $company->district,
            'email' => $company->email,
            'phone' => $company->phone,
            'logo_url' => $company->logo_path,
        ];

        $isAdvance = (bool) ($validated['is_advance'] ?? false);
        $totalDoc = (float) $totals['total'];
        $advanceTotal = $isAdvance ? $totalDoc : 0.00;
        $advanceBalance = $isAdvance ? $totalDoc : 0.00;

        $hasDeductions = !empty($validated['applied_advances']);

        $document = DB::transaction(function () use (
            $validated,
            $series,
            $typeCode,
            $totals,
            $client,
            $isProduction,
            $companyData,
            $isAdvance,
            $advanceTotal,
            $advanceBalance,
            $hasDeductions
        ) {
            $doc = Document::create([
                'company_id' => $validated['company_id'],
                'company_data' => $companyData,
                'external_id' => $validated['external_id'] ?? null,
                'is_production' => $isProduction,
                'type_code' => $typeCode,
                'operation_type' => $validated['operation_type'] ?? '0101',
                'establishment_code' => $validated['establishment_code'] ?? '0000',
                'series' => $series,
                'correlative' => $validated['correlative'],
                'issue_date' => $validated['issue_date'],
                'issue_time' => $validated['issue_time'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $validated['currency'],
                'payment_method' => strtolower($validated['payment_method'] ?? 'contado'),
                'installments' => $validated['installments'] ?? null,
                'detraction' => $validated['detraction'] ?? null,
                'retention' => $validated['retention'] ?? null,
                'prepayments' => $validated['prepayments'] ?? null,
                'related_documents' => $validated['related_documents'] ?? null,
                'note_data' => $validated['note'] ?? null,
                'purchase_order' => $validated['purchase_order'] ?? null,
                'plate_number' => $validated['plate_number'] ?? null,
                'extra_fields' => $validated['extra_fields'] ?? null,
                'observations' => $validated['observations'] ?? null,
                'pie_pagina' => $validated['pie_pagina'] ?? null,
                'is_advance' => $isAdvance,
                'advance_total' => $advanceTotal,
                'advance_balance' => $advanceBalance,
                'has_deductions' => $hasDeductions,
                'client_doc_type' => $client['doc_type'],
                'client_doc_number' => $client['doc_number'],
                'client_name' => $client['name'],
                'client_address' => $client['address'] ?? null,
                'client_email' => $client['email'] ?? null,
                'total_taxable' => $totals['taxable'] ?? 0.00,
                'total_unaffected' => $totals['unaffected'] ?? 0.00,
                'total_exonerated' => $totals['exonerated'] ?? 0.00,
                'total_free' => $totals['free'] ?? 0.00,
                'total_exportation' => $totals['exportation'] ?? 0.00,
                'total_igv' => $totals['igv'] ?? 0.00,
                'total_icbper' => $totals['icbper'] ?? 0.00,
                'total_discount' => $totals['discount'] ?? 0.00,
                'total' => $totals['total'],
                'status' => 'pending',
            ]);

            foreach ($validated['items'] as $item) {
                $doc->items()->create([
                    'internal_code' => $item['internal_code'] ?? null,
                    'description' => $item['description'],
                    'unit_code' => $item['unit_code'],
                    'quantity' => $item['quantity'],
                    'unit_value' => $item['unit_value'],
                    'unit_price' => $item['unit_price'],
                    'igv_type' => $item['igv_type'],
                    'igv_amount' => $item['igv_amount'],
                    'total' => $item['total'],
                    'attributes' => $item['attributes'] ?? null,
                ]);
            }

            // Registrar anticipos aplicados y descontar saldo
            if (!empty($validated['applied_advances'])) {
                foreach ($validated['applied_advances'] as $advItem) {
                    $amount = (float) $advItem['amount'];
                    $advanceDocId = $advItem['advance_document_id'] ?? null;

                    if ($advanceDocId) {
                        $advanceDoc = Document::where('id', $advanceDocId)
                            ->where('company_id', $validated['company_id'])
                            ->first();

                        if ($advanceDoc) {
                            $newBalance = max(0.00, (float) $advanceDoc->advance_balance - $amount);
                            $advanceDoc->update(['advance_balance' => $newBalance]);
                        }
                    }

                    AppliedAdvance::create([
                        'document_id' => $doc->id,
                        'advance_document_id' => $advanceDocId,
                        'advance_type_code' => $advItem['type_code'] ?? '01',
                        'advance_series' => strtoupper($advItem['series']),
                        'advance_correlative' => (string) $advItem['correlative'],
                        'advance_issue_date' => $advItem['issue_date'] ?? null,
                        'amount' => $amount,
                        'description' => $advItem['description'] ?? 'Anticipo deducido',
                    ]);
                }
            }

            // Si es Nota de Crédito por anulación (tipo 07, motivo 01), revertir anticipos aplicados en la factura original
            if ($typeCode === '07' && !empty($validated['note']['code']) && $validated['note']['code'] === '01') {
                $affectedSeries = strtoupper($validated['note']['affected_series'] ?? '');
                $affectedCorr = (int) ($validated['note']['affected_correlative'] ?? 0);

                $affectedDoc = Document::where('company_id', $validated['company_id'])
                    ->where('series', $affectedSeries)
                    ->where('correlative', $affectedCorr)
                    ->first();

                if ($affectedDoc) {
                    $applieds = AppliedAdvance::where('document_id', $affectedDoc->id)
                        ->where('is_reversed', false)
                        ->get();

                    foreach ($applieds as $app) {
                        if ($app->advance_document_id) {
                            $origAdv = Document::find($app->advance_document_id);
                            if ($origAdv) {
                                $origAdv->increment('advance_balance', (float) $app->amount);
                            }
                        }
                        $app->update(['is_reversed' => true]);
                    }
                }
            }

            return $doc;
        });

        // Dispatch background processing job to high queue
        ProcessDocumentJob::dispatch($document)->onQueue('high');

        $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');

        return response()->json([
            'status' => 'success',
            'message' => 'Comprobante recibido y encolado para procesamiento.',
            'data' => [
                'id' => $document->id,
                'md5' => md5($document->id),
                'external_id' => $document->external_id,
                'document' => $document->getDocumentNumber(),
                'type_code' => $document->type_code,
                'status' => 'pending',
                'is_production' => (bool) $document->is_production,
                'is_test' => !$document->is_production,
                'is_advance' => (bool) $document->is_advance,
                'advance_balance' => (float) $document->advance_balance,
                'links' => [
                    'pdf' => "{$baseUrl}/api/v1/documents/{$document->id}/pdf",
                    'pdf_md5' => "{$baseUrl}/api/v1/documents/" . md5($document->id) . "/pdf",
                    'xml' => "{$baseUrl}/api/v1/documents/{$document->id}/xml",
                    'cdr' => "{$baseUrl}/api/v1/documents/{$document->id}/cdr",
                ],
            ],
        ], 202);
    }
}
