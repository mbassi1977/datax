<?php

namespace App\Imports;

/** Normalización y validación de RUT chileno (módulo 11). */
class Rut
{
    public static function clean(?string $rut): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', (string) $rut));
    }

    public static function isValid(?string $rut): bool
    {
        $c = self::clean($rut);
        if (strlen($c) < 2) {
            return false;
        }
        $body = substr($c, 0, -1);
        if (! ctype_digit($body)) {
            return false;
        }

        return self::dv($body) === substr($c, -1);
    }

    /** 12.345.678-5 */
    public static function format(?string $rut): ?string
    {
        $c = self::clean($rut);
        if (strlen($c) < 2) {
            return null;
        }

        return number_format((int) substr($c, 0, -1), 0, '', '.').'-'.substr($c, -1);
    }

    /** Forma comparable (sin puntos ni guion): 123456785 */
    public static function key(?string $rut): string
    {
        return self::clean($rut);
    }

    private static function dv(string $body): string
    {
        $sum = 0;
        $mul = 2;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $mul;
            $mul = $mul === 7 ? 2 : $mul + 1;
        }
        $r = 11 - ($sum % 11);

        return $r === 11 ? '0' : ($r === 10 ? 'K' : (string) $r);
    }
}
