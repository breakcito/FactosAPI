<?php

use App\Services\ApisPeruService;
use Illuminate\Support\Facades\Http;

test('ApisPeruService resolves DNI successfully', function () {
    Http::fake([
        'https://dniruc.apisperu.com/api/v1/dni/*' => Http::response([
            'success' => true,
            'dni' => '72728282',
            'nombres' => 'GIANFRANCO ALESSANDRO',
            'apellidoPaterno' => 'SULLCA',
            'apellidoMaterno' => 'ORTIZ',
            'codVerifica' => 9,
        ], 200),
    ]);

    $service = new ApisPeruService;
    $data = $service->getDni('72728282');

    expect($data['dni'])->toBe('72728282');
    expect($data['nombres'])->toBe('GIANFRANCO ALESSANDRO');
    expect($data['apellido_paterno'])->toBe('SULLCA');
    expect($data['codigo_verificacion'])->toBe('9');
});

test('ApisPeruService resolves RUC successfully', function () {
    Http::fake([
        'https://dniruc.apisperu.com/api/v1/ruc/*' => Http::response([
            'ruc' => '20131312955',
            'razonSocial' => 'SUNAT',
            'estado' => 'ACTIVO',
            'condicion' => 'HABIDO',
            'direccion' => 'AV. GARCILASO DE LA VEGA 1472',
            'ubigeo' => '150101',
        ], 200),
    ]);

    $service = new ApisPeruService;
    $data = $service->getRuc('20131312955');

    expect($data['ruc'])->toBe('20131312955');
    expect($data['razon_social'])->toBe('SUNAT');
    expect($data['estado'])->toBe('ACTIVO');
    expect($data['condicion'])->toBe('HABIDO');
    expect($data['ubigeo'])->toBe('150101');
});

test('ApisPeruService resolves exchange rate successfully', function () {
    Http::fake([
        'https://tipocambio.apisperu.com/api/v1/sunat*' => Http::response([
            'success' => true,
            'date' => '2026-09-24',
            'source' => 'SUNAT',
            'rates' => [
                'USD' => [
                    'name' => 'Dólar de N.A.',
                    'buy' => '3.3800',
                    'sell' => '3.3850',
                ],
            ],
        ], 200),
    ]);

    $service = new ApisPeruService;
    $data = $service->getExchangeRate('sunat', '2026-09-24');

    expect($data['source'])->toBe('SUNAT');
    expect($data['compra'])->toBe(3.38);
    expect($data['venta'])->toBe(3.385);
});
