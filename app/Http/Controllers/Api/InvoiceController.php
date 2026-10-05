<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Jobs\ProcessDocumentJob;
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

        $totals = $validated['totals'];
        $client = $validated['client'];

        $document = DB::transaction(function () use ($validated, $series, $typeCode, $totals, $client) {
            $doc = Document::create([
                'company_id' => $validated['company_id'],
                'external_id' => $validated['external_id'] ?? null,
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

            return $doc;
        });

        // Dispatch background processing job to high queue
        ProcessDocumentJob::dispatch($document)->onQueue('high');

        return response()->json([
            'status' => 'success',
            'message' => 'Comprobante recibido y encolado para procesamiento.',
            'data' => [
                'id' => $document->id,
                'external_id' => $document->external_id,
                'document' => $document->getDocumentNumber(),
                'type_code' => $document->type_code,
                'status' => 'pending',
            ],
        ], 202);
    }
}
