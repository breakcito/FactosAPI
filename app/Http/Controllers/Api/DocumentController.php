<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function show(Document $document): JsonResponse
    {
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
                'hash' => $document->hash,
                'links' => [
                    'xml' => "{$baseUrl}/api/v1/documents/{$document->id}/xml",
                    'cdr' => "{$baseUrl}/api/v1/documents/{$document->id}/cdr",
                    'pdf' => "{$baseUrl}/api/v1/documents/{$document->id}/pdf",
                ],
                'items' => $document->items,
            ],
        ]);
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
                'message' => 'Archivo PDF no encontrado o en proceso de generación.',
            ], 404);
        }

        $pdf = Storage::disk($disk)->get($document->pdf_path);
        $filename = "{$document->getSunatFileName()}.pdf";

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
