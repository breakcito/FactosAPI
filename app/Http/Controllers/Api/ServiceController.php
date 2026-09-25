<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApisPeruService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ServiceController extends Controller
{
    public function __construct(
        protected ApisPeruService $apisPeruService
    ) {}

    public function dni(string $number): JsonResponse
    {
        if (! preg_match('/^\d{8}$/', $number)) {
            return response()->json([
                'status' => 'error',
                'message' => 'El número de DNI debe contener exactamente 8 dígitos.',
            ], 422);
        }

        try {
            $data = $this->apisPeruService->getDni($number);

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    public function ruc(string $number): JsonResponse
    {
        if (! preg_match('/^(10|20)\d{9}$/', $number)) {
            return response()->json([
                'status' => 'error',
                'message' => 'El número de RUC debe tener 11 dígitos y comenzar con 10 o 20.',
            ], 422);
        }

        try {
            $data = $this->apisPeruService->getRuc($number);

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    public function exchangeRate(Request $request): JsonResponse
    {
        $source = $request->query('source', 'sunat');
        $date = $request->query('date', now()->toDateString());

        try {
            $data = $this->apisPeruService->getExchangeRate($source, $date);

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 404);
        }
    }
}
