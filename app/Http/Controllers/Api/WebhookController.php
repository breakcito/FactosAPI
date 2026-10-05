<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function retry(Request $request, WebhookDelivery $webhook): JsonResponse
    {
        $webhook->loadMissing(['document', 'despatch', 'company']);

        abort_if($webhook->company->user_id !== $request->user()->id, 403, 'No tiene autorización para reintentar este webhook.');

        $target = $webhook->document ?? $webhook->despatch;

        if (! $target) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró el comprobante o guía asociado a este webhook.',
            ], 404);
        }

        SendWebhookJob::dispatch($target, $webhook->event)->onQueue('webhooks');

        return response()->json([
            'status' => 'success',
            'message' => 'Notificación de webhook re-encolada para entrega.',
            'data' => $webhook,
        ]);
    }
}
