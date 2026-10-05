<?php

use App\Services\Support\NumeroALetras;

test('NumeroALetras converts numbers to words in PEN, USD, and EUR', function () {
    expect(NumeroALetras::convert(100.50, 'PEN'))
        ->toBe('SON CIEN CON 50/100 SOLES');

    expect(NumeroALetras::convert(1250.00, 'USD'))
        ->toBe('SON MIL DOSCIENTOS CINCUENTA CON 00/100 DÓLARES AMERICANOS');

    expect(NumeroALetras::convert(450.75, 'EUR'))
        ->toBe('SON CUATROCIENTOS CINCUENTA CON 75/100 EUROS');
});
