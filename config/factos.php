<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ApisPeru Configuration (DNI, RUC, Exchange Rate)
    |--------------------------------------------------------------------------
    */
    'apisperu' => [
        'dni_ruc_url' => env('APISPERU_DNI_RUC_URL', 'https://dniruc.apisperu.com/api/v1'),
        'exchange_rate_url' => env('APISPERU_TC_URL', 'https://tipocambio.apisperu.com/api/v1'),
        'token_dni_ruc' => env('API_KEY_DNI_RUC', ''),
        'token_exchange_rate' => env('API_KEY_TC', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    | Storage path structure per tenant:
    | tenants/{ruc}/{year}/{month}/{tipo-serie-correlativo}.ext
    */
    'storage_disk' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Exponential Backoff Retry Intervals (seconds)
    |--------------------------------------------------------------------------
    | (1m, 5m, 15m, 1h, 4h)
    */
    'retry_delays' => [
        60,     // 1 min
        300,    // 5 mins
        900,    // 15 mins
        3600,   // 1 hour
        14400,  // 4 hours
    ],
];
