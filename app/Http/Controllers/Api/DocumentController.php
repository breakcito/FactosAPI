<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VoidDocumentRequest;
use App\Jobs\VoidDocumentJob;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userCompanyIds = $request->user()->companies()->pluck('id');

        $query = Document::query()
            ->whereIn('company_id', $userCompanyIds)
            ->with('company');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        if ($request->filled('type_code')) {
            $query->where('type_code', $request->query('type_code'));
        }

        if ($request->filled('series')) {
            $query->where('series', strtoupper((string) $request->query('series')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('issue_date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('issue_date', '<=', $request->query('date_to'));
        }

        if ($request->filled('client_doc_number')) {
            $query->where('client_doc_number', $request->query('client_doc_number'));
        }

        $documents = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $documents,
        ]);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        abort_if($document->company->user_id !== $request->user()->id, 403, 'No tiene autorización para consultar este comprobante.');

        $document->loadMissing(['company', 'items']);
        $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $document->id,
                'external_id' => $document->external_id,
                'document_type' => $document->type_code,
                'series' => $document->series,
                'correlative' => $document->correlative,
                'document_number' => $document->getDocumentNumber(),
                'status' => $document->status,
                'issue_date' => $document->issue_date->toDateString(),
                'issue_time' => $document->issue_time,
                'currency' => $document->currency,
                'payment_method' => $document->payment_method,
                'installments' => $document->installments,
                'detraction' => $document->detraction,
                'retention' => $document->retention,
                'prepayments' => $document->prepayments,
                'related_documents' => $document->related_documents,
                'note' => $document->note_data,
                'total' => (float) $document->total,
                'client' => [
                    'doc_type' => $document->client_doc_type,
                    'doc_number' => $document->client_doc_number,
                    'name' => $document->client_name,
                    'address' => $document->client_address,
                    'email' => $document->client_email,
                ],
                'sunat' => [
                    'code' => $document->sunat_code,
                    'description' => $document->sunat_description,
                    'notes' => $document->sunat_notes,
                ],
                'void' => [
                    'ticket' => $document->void_ticket,
                    'reason' => $document->void_reason,
                    'sunat_code' => $document->void_sunat_code,
                    'sunat_description' => $document->void_sunat_description,
                    'voided_at' => $document->voided_at?->toIso8601String(),
                ],
                'hash' => $document->hash,
                'links' => [
                    'xml' => "{$baseUrl}/api/v1/documents/{$document->id}/xml",
                    'cdr' => "{$baseUrl}/api/v1/documents/{$document->id}/cdr",
                    'pdf' => "{$baseUrl}/api/v1/documents/{$document->id}/pdf",
                    'void_xml' => $document->void_xml_path ? "{$baseUrl}/api/v1/documents/{$document->id}/void-xml" : null,
                    'void_cdr' => $document->void_cdr_path ? "{$baseUrl}/api/v1/documents/{$document->id}/void-cdr" : null,
                ],
                'items' => $document->items,
            ],
        ]);
    }

    public function void(VoidDocumentRequest $request, ?Document $document = null): JsonResponse
    {
        $validated = $request->validated();
        $reason = $validated['reason'];

        if (! $document) {
            $userCompanyIds = $request->user()->companies()->pluck('id');
            $document = Document::whereIn('company_id', $userCompanyIds)
                ->where('company_id', $validated['company_id'])
                ->where('type_code', $validated['type_code'])
                ->where('series', strtoupper($validated['series']))
                ->where('correlative', $validated['correlative'])
                ->first();

            if (! $document) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontró el comprobante que se desea anular o no pertenece a sus empresas.',
                ], 404);
            }
        } else {
            abort_if($document->company->user_id !== $request->user()->id, 403, 'No tiene autorización para anular este comprobante.');
        }

        if ($document->status === 'voided') {
            return response()->json([
                'status' => 'error',
                'message' => 'El comprobante ya se encuentra anulado ante SUNAT.',
            ], 422);
        }

        if ($document->status === 'void_pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'El comprobante ya tiene una solicitud de anulación en proceso.',
            ], 422);
        }

        if ($document->status !== 'accepted') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden anular comprobantes que hayan sido previamente aceptados por SUNAT.',
            ], 422);
        }

        $document->update([
            'status' => 'void_pending',
            'void_reason' => $reason,
        ]);

        VoidDocumentJob::dispatch($document, $reason)->onQueue('sunat');

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud de anulación recibida y en proceso ante SUNAT.',
            'data' => [
                'id' => $document->id,
                'external_id' => $document->external_id,
                'document' => $document->getDocumentNumber(),
                'status' => 'void_pending',
                'reason' => $reason,
            ],
        ], 202);
    }

    public function xml(Document $document): Response|JsonResponse|BinaryFileResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $document->xml_path || ! Storage::disk($disk)->exists($document->xml_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo XML no encontrado o comprobante aún no firmado.',
            ], 404);
        }

        /** @var string $xml */
        $xml = Storage::disk($disk)->get($document->xml_path);
        $filename = "{$document->getSunatFileName()}.xml";

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function cdr(Document $document): Response|JsonResponse|BinaryFileResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $document->cdr_path || ! Storage::disk($disk)->exists($document->cdr_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo CDR no disponible o aún no emitido por SUNAT.',
            ], 404);
        }

        /** @var string $cdrZip */
        $cdrZip = Storage::disk($disk)->get($document->cdr_path);
        $filename = "R-{$document->getSunatFileName()}.zip";

        return response($cdrZip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function pdf(Document $document): Response|JsonResponse|BinaryFileResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $document->pdf_path || ! Storage::disk($disk)->exists($document->pdf_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo PDF no disponible para este comprobante.',
            ], 404);
        }

        /** @var string $pdf */
        $pdf = Storage::disk($disk)->get($document->pdf_path);
        $filename = "{$document->getSunatFileName()}.pdf";

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    public function voidXml(Document $document): Response|JsonResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $document->void_xml_path || ! Storage::disk($disk)->exists($document->void_xml_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo XML de anulación no encontrado.',
            ], 404);
        }

        /** @var string $xml */
        $xml = Storage::disk($disk)->get($document->void_xml_path);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="VOID-'.basename($document->void_xml_path).'"',
        ]);
    }

    public function voidCdr(Document $document): Response|JsonResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $document->void_cdr_path || ! Storage::disk($disk)->exists($document->void_cdr_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo CDR de anulación no encontrado.',
            ], 404);
        }

        /** @var string $cdrZip */
        $cdrZip = Storage::disk($disk)->get($document->void_cdr_path);

        return response($cdrZip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="R-VOID-'.basename($document->void_cdr_path).'"',
        ]);
    }
}
