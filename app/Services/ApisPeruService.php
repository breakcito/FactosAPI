<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApisPeruService
{
    protected string $dniRucBaseUrl;

    protected string $tcBaseUrl;

    protected string $dniRucToken;

    protected string $tcToken;

    public function __construct()
    {
        $this->dniRucBaseUrl = SystemSetting::get('dni_ruc_url', config('factos.apisperu.dni_ruc_url', 'https://dniruc.apisperu.com/api/v1'));
        $this->tcBaseUrl = SystemSetting::get('exchange_rate_url', config('factos.apisperu.exchange_rate_url', 'https://tipocambio.apisperu.com/api/v1'));
        $this->dniRucToken = (string) (SystemSetting::get('api_key_dni_ruc') ?: config('factos.apisperu.token_dni_ruc', ''));
        $this->tcToken = (string) (SystemSetting::get('api_key_tc') ?: config('factos.apisperu.token_exchange_rate', ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDni(string $dni): array
    {
        $cacheKey = "service:dni:{$dni}";

        return Cache::remember($cacheKey, now()->addDay(), function () use ($dni) {
            $url = "{$this->dniRucBaseUrl}/dni/{$dni}";
            $response = Http::withToken($this->dniRucToken)
                ->timeout(10)
                ->get($url);

            if (!$response->successful()) {
                throw new RuntimeException("Error al consultar DNI ({$response->status()}): {$response->body()}");
            }

            $json = $response->json();
            if (isset($json['success']) && $json['success'] === false) {
                throw new RuntimeException($json['message'] ?? 'DNI no encontrado');
            }

            return [
                'dni' => $json['dni'] ?? $dni,
                'nombres' => $json['nombres'] ?? '',
                'apellido_paterno' => $json['apellidoPaterno'] ?? '',
                'apellido_materno' => $json['apellidoMaterno'] ?? '',
                'codigo_verificacion' => (string) ($json['codVerifica'] ?? ''),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuc(string $ruc): array
    {
        $cacheKey = "service:ruc:{$ruc}";

        return Cache::remember($cacheKey, now()->addDay(), function () use ($ruc) {
            $url = "{$this->dniRucBaseUrl}/ruc/{$ruc}";
            $response = Http::withToken($this->dniRucToken)
                ->timeout(10)
                ->get($url);

            if (!$response->successful()) {
                throw new RuntimeException("Error al consultar RUC ({$response->status()}): {$response->body()}");
            }

            $json = $response->json();
            if (isset($json['success']) && $json['success'] === false) {
                throw new RuntimeException($json['message'] ?? 'RUC no encontrado');
            }

            return [
                'ruc' => $json['ruc'] ?? $ruc,
                'razon_social' => $json['razonSocial'] ?? '',
                'nombre_comercial' => $json['nombreComercial'] ?? null,
                'estado' => $json['estado'] ?? '',
                'condicion' => $json['condicion'] ?? '',
                'direccion' => $json['direccion'] ?? '',
                'departamento' => $json['departamento'] ?? null,
                'provincia' => $json['provincia'] ?? null,
                'distrito' => $json['distrito'] ?? null,
                'ubigeo' => $json['ubigeo'] ?? null,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getExchangeRate(?string $source = 'sunat', ?string $date = null): array
    {
        $src = strtolower($source ?: 'sunat');
        $dt = $date ?: now()->toDateString();
        $cacheKey = "service:tc:{$src}:{$dt}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($src, $dt) {
            $endpoint = ($src === 'sbs') ? 'sbs' : 'sunat';
            $url = "{$this->tcBaseUrl}/{$endpoint}?date={$dt}";

            $response = Http::withToken($this->tcToken)
                ->timeout(10)
                ->get($url);

            if (!$response->successful()) {
                throw new RuntimeException("Error al consultar tipo de cambio ({$response->status()}): {$response->body()}");
            }

            $json = $response->json();
            $rates = $json['rates']['USD'] ?? null;

            $compra = $rates ? (float) ($rates['buy'] ?? 0) : (float) ($json['compra'] ?? $json['buy'] ?? 0);
            $venta = $rates ? (float) ($rates['sell'] ?? 0) : (float) ($json['venta'] ?? $json['sell'] ?? 0);

            return [
                'date' => $json['date'] ?? $dt,
                'source' => strtoupper($src),
                'currency' => 'USD',
                'compra' => $compra,
                'venta' => $venta,
            ];
        });
    }
}
