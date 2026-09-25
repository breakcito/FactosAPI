<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDespatchRequest;
use App\Jobs\ProcessDespatchJob;
use App\Models\Despatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DespatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Despatch::query()->with('company');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
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

        $despatches = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $despatches,
        ]);
    }

    public function store(StoreDespatchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $series = strtoupper($validated['series']);
        $recipient = $validated['recipient'];
        $origin = $validated['origin'];
        $destination = $validated['destination'];
        $carrier = $validated['carrier'] ?? [];
        $driver = $validated['driver'] ?? [];
        $vehicle = $validated['vehicle'] ?? [];

        $despatch = DB::transaction(function () use ($validated, $series, $recipient, $origin, $destination, $carrier, $driver, $vehicle) {
            $desp = Despatch::create([
                'company_id' => $validated['company_id'],
                'external_id' => $validated['external_id'] ?? null,
                'type_code' => $validated['type_code'] ?? '09',
                'series' => $series,
                'correlative' => $validated['correlative'],
                'issue_date' => $validated['issue_date'],
                'issue_time' => $validated['issue_time'],
                'transfer_date' => $validated['transfer_date'],
                'delivery_date' => $validated['delivery_date'] ?? null,
                'transport_mode' => $validated['transport_mode'],
                'transfer_reason' => $validated['transfer_reason'],
                'transfer_description' => $validated['transfer_description'] ?? null,
                'total_weight' => $validated['total_weight'],
                'weight_unit' => $validated['weight_unit'],
                'packages_count' => $validated['packages_count'],
                'recipient_doc_type' => $recipient['doc_type'],
                'recipient_doc_number' => $recipient['doc_number'],
                'recipient_name' => $recipient['name'],
                'recipient_address' => $recipient['address'] ?? null,
                'recipient_email' => $recipient['email'] ?? null,
                'origin_ubigeo' => $origin['ubigeo'],
                'origin_address' => $origin['address'],
                'destination_ubigeo' => $destination['ubigeo'],
                'destination_address' => $destination['address'],
                'carrier_doc_type' => $carrier['doc_type'] ?? null,
                'carrier_doc_number' => $carrier['doc_number'] ?? null,
                'carrier_name' => $carrier['name'] ?? null,
                'carrier_mtc' => $carrier['mtc'] ?? null,
                'driver_doc_type' => $driver['doc_type'] ?? null,
                'driver_doc_number' => $driver['doc_number'] ?? null,
                'driver_name' => $driver['name'] ?? null,
                'driver_license' => $driver['license'] ?? null,
                'vehicle_plate' => $vehicle['plate'] ?? null,
                'secondary_vehicle_plate' => $vehicle['secondary_plate'] ?? null,
                'related_documents' => $validated['related_documents'] ?? null,
                'status' => 'pending',
            ]);

            foreach ($validated['items'] as $item) {
                $desp->items()->create([
                    'internal_code' => $item['internal_code'] ?? null,
                    'description' => $item['description'],
                    'unit_code' => $item['unit_code'] ?? 'NIU',
                    'quantity' => $item['quantity'],
                ]);
            }

            return $desp;
        });

        // Dispatch background processing job
        ProcessDespatchJob::dispatch($despatch)->onQueue('high');

        return response()->json([
            'status' => 'success',
            'message' => 'Guía de remisión recibida y encolada para procesamiento.',
            'data' => [
                'id' => $despatch->id,
                'external_id' => $despatch->external_id,
                'document' => $despatch->getDocumentNumber(),
                'type_code' => $despatch->type_code,
                'status' => 'pending',
            ],
        ], 202);
    }

    public function show(Despatch $despatch): JsonResponse
    {
        $despatch->loadMissing(['company', 'items']);
        $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $despatch->id,
                'external_id' => $despatch->external_id,
                'document_type' => $despatch->type_code,
                'series' => $despatch->series,
                'correlative' => $despatch->correlative,
                'document_number' => $despatch->getDocumentNumber(),
                'status' => $despatch->status,
                'issue_date' => $despatch->issue_date->toDateString(),
                'issue_time' => $despatch->issue_time,
                'transfer_date' => $despatch->transfer_date->toDateString(),
                'transport_mode' => $despatch->transport_mode,
                'transfer_reason' => $despatch->transfer_reason,
                'total_weight' => (float) $despatch->total_weight,
                'weight_unit' => $despatch->weight_unit,
                'packages_count' => $despatch->packages_count,
                'recipient' => [
                    'doc_type' => $despatch->recipient_doc_type,
                    'doc_number' => $despatch->recipient_doc_number,
                    'name' => $despatch->recipient_name,
                    'address' => $despatch->recipient_address,
                    'email' => $despatch->recipient_email,
                ],
                'origin' => [
                    'ubigeo' => $despatch->origin_ubigeo,
                    'address' => $despatch->origin_address,
                ],
                'destination' => [
                    'ubigeo' => $despatch->destination_ubigeo,
                    'address' => $despatch->destination_address,
                ],
                'transport' => $despatch->isPublicTransport() ? [
                    'carrier_name' => $despatch->carrier_name,
                    'carrier_doc_number' => $despatch->carrier_doc_number,
                    'carrier_mtc' => $despatch->carrier_mtc,
                ] : [
                    'driver_name' => $despatch->driver_name,
                    'driver_doc_number' => $despatch->driver_doc_number,
                    'driver_license' => $despatch->driver_license,
                    'vehicle_plate' => $despatch->vehicle_plate,
                ],
                'sunat' => [
                    'code' => $despatch->sunat_code,
                    'description' => $despatch->sunat_description,
                    'notes' => $despatch->sunat_notes,
                ],
                'hash' => $despatch->hash,
                'links' => [
                    'xml' => "{$baseUrl}/api/v1/despatches/{$despatch->id}/xml",
                    'cdr' => "{$baseUrl}/api/v1/despatches/{$despatch->id}/cdr",
                    'pdf' => "{$baseUrl}/api/v1/despatches/{$despatch->id}/pdf",
                ],
                'items' => $despatch->items,
            ],
        ]);
    }

    public function void(Request $request, Despatch $despatch): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ]);

        if ($despatch->status === 'voided') {
            return response()->json([
                'status' => 'error',
                'message' => 'La guía ya se encuentra anulada.',
            ], 422);
        }

        if ($despatch->status !== 'accepted') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden anular guías previamente aceptadas por SUNAT.',
            ], 422);
        }

        $reason = (string) $request->input('reason');
        $despatch->update([
            'status' => 'voided',
            'void_reason' => $reason,
            'voided_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Guía de remisión anulada exitosamente.',
            'data' => [
                'id' => $despatch->id,
                'external_id' => $despatch->external_id,
                'document' => $despatch->getDocumentNumber(),
                'status' => 'voided',
                'reason' => $reason,
            ],
        ]);
    }

    public function xml(Despatch $despatch): Response|JsonResponse|BinaryFileResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $despatch->xml_path || ! Storage::disk($disk)->exists($despatch->xml_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo XML no encontrado o guía aún no firmada.',
            ], 404);
        }

        /** @var string $xml */
        $xml = Storage::disk($disk)->get($despatch->xml_path);
        $filename = "{$despatch->getSunatFileName()}.xml";

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function cdr(Despatch $despatch): Response|JsonResponse|BinaryFileResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $despatch->cdr_path || ! Storage::disk($disk)->exists($despatch->cdr_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo CDR no disponible para esta guía.',
            ], 404);
        }

        /** @var string $cdrZip */
        $cdrZip = Storage::disk($disk)->get($despatch->cdr_path);
        $filename = "R-{$despatch->getSunatFileName()}.zip";

        return response($cdrZip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function pdf(Despatch $despatch): Response|JsonResponse|BinaryFileResponse
    {
        $disk = config('factos.storage_disk', 'local');

        if (! $despatch->pdf_path || ! Storage::disk($disk)->exists($despatch->pdf_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Archivo PDF no disponible para esta guía.',
            ], 404);
        }

        /** @var string $pdf */
        $pdf = Storage::disk($disk)->get($despatch->pdf_path);
        $filename = "{$despatch->getSunatFileName()}.pdf";

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
