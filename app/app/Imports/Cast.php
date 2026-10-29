<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Support\Str;

/** Conversiones tolerantes de celdas de planillas. */
class Cast
{
    /** Texto comparable: minúsculas, sin tildes ni signos. */
    public static function norm(?string $v): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii((string) $v))));
    }

    /** Fecha desde "2026-01-31", "31/01/2026", "31-01-26", "31.01.2026" o serial de Excel. Null si es inválida. */
    public static function date(string $v): ?string
    {
        $v = trim($v);
        if (ctype_digit($v) && (int) $v > 20000 && (int) $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!'.$format, $v);
            } catch (\Throwable) {
                continue; // no coincide con este formato
            }
            if ($d && $d->format($format) === $v && $d->year >= 1900 && $d->year <= 2100) {
                return $d->toDateString();
            }
        }

        return null;
    }

    public static function status(string $v): ?string
    {
        return match (self::norm($v)) {
            'activo', 'activa', 'active', 'vigente', 'si', '1' => 'active',
            'inactivo', 'inactiva', 'inactive', 'no', '0', 'baja' => 'inactive',
            default => null,
        };
    }
}
