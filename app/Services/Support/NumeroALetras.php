<?php

namespace App\Services\Support;

class NumeroALetras
{
    /** @var array<int, string> */
    private static array $unidades = [
        '', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE',
        'DIECIOCHO', 'DIECINUEVE', 'VEINTE',
    ];

    /** @var array<int, string> */
    private static array $decenas = [
        '', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA',
        'OCHENTA', 'NOVENTA',
    ];

    /** @var array<int, string> */
    private static array $centenas = [
        '', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS',
    ];

    public static function convert(float|string $number, string $currency = 'PEN'): string
    {
        $amount = (float) $number;
        $cents = (int) round(($amount - floor($amount)) * 100);
        $intPart = (int) floor($amount);

        $currencyName = match (strtoupper($currency)) {
            'USD' => 'DÓLARES AMERICANOS',
            'EUR' => 'EUROS',
            default => 'SOLES',
        };

        if ($intPart === 0) {
            $words = 'CERO';
        } else {
            $words = self::convertNumber($intPart);
        }

        return sprintf('SON %s CON %02d/100 %s', trim($words), $cents, $currencyName);
    }

    private static function convertNumber(int $n): string
    {
        if ($n < 0) {
            return 'MENOS '.self::convertNumber(abs($n));
        }

        if ($n <= 20) {
            return self::$unidades[$n];
        }

        if ($n < 30) {
            return 'VEINTI'.self::$unidades[$n - 20];
        }

        if ($n < 100) {
            $dec = (int) ($n / 10);
            $rem = $n % 10;

            return self::$decenas[$dec].($rem > 0 ? ' Y '.self::$unidades[$rem] : '');
        }

        if ($n === 100) {
            return 'CIEN';
        }

        if ($n < 1000) {
            $cen = (int) ($n / 100);
            $rem = $n % 100;

            return self::$centenas[$cen].($rem > 0 ? ' '.self::convertNumber($rem) : '');
        }

        if ($n === 1000) {
            return 'MIL';
        }

        if ($n < 1000000) {
            $miles = (int) ($n / 1000);
            $rem = $n % 1000;
            $milesStr = ($miles === 1) ? 'MIL' : self::convertNumber($miles).' MIL';

            return $milesStr.($rem > 0 ? ' '.self::convertNumber($rem) : '');
        }

        if ($n < 2000000) {
            $rem = $n % 1000000;

            return 'UN MILLÓN'.($rem > 0 ? ' '.self::convertNumber($rem) : '');
        }

        if ($n < 1000000000) {
            $millones = (int) ($n / 1000000);
            $rem = $n % 1000000;

            return self::convertNumber($millones).' MILLONES'.($rem > 0 ? ' '.self::convertNumber($rem) : '');
        }

        return (string) $n;
    }
}
