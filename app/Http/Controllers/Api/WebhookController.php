<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    public function retry(WebhookDelivery $webhook): JsonResponse
    {
        $webhook->loadMissing(['document']);

        SendWebhookJob::dispatch($webhook->document, $webhook->event)->onQueue('webhooks');

        return response()->json([
            'status' => 'success',
            'message' => 'Notificación de webhook re-encolada para entrega.',
            'data' => $webhook,
        ]);
    }
}
