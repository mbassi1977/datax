<?php

namespace App\Support\Sellout;

/**
 * Ubica una sala por la comuna de su dirección (o, si no hay dirección, por palabras del nombre).
 * Es una ubicación aproximada: el centro de la comuna con un pequeño desplazamiento determinista para que las salas no se apilen.
 * Cuando se geocodifica o se corrige a mano, la calidad pasa a «exact».
 */
class CommuneLocator
{
    private static ?array $places = null;
    private static ?array $keys = null;

    public static function norm(string $s): string
    {
        $s = strtolower(\Illuminate\Support\Str::ascii($s));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    private static function load(): void
    {
        if (self::$places === null) {
            self::$places = json_decode((string) file_get_contents(resource_path('geo/places.json')), true) ?: [];
            self::$keys = array_keys(self::$places);
            usort(self::$keys, fn ($a, $b) => strlen($b) <=> strlen($a));   // coincidencia más larga primero
        }
    }

    /** Comuna que cierra una dirección chilena: «Av. X 123, Las Condes» → «Las Condes». */
    public static function communeOf(?string $address): ?string
    {
        if (! $address || ! str_contains($address, ',')) {
            return null;
        }
        $last = trim(preg_replace('/\d+/', '', (string) substr(strrchr($address, ','), 1)));

        return $last !== '' ? $last : null;
    }

    /** @return array{lat:float,lon:float,region:?string,commune:?string,quality:string}|null */
    public static function locate(?string $address, ?string $name = null, string $seed = ''): ?array
    {
        self::load();
        $hit = null;
        $commune = self::communeOf($address);
        if ($commune !== null) {
            $k = self::norm($commune);
            if (isset(self::$places[$k])) {
                $hit = [$k, 'commune'];
            }
        }
        if (! $hit) {
            foreach (array_filter([$address, $name]) as $text) {
                $t = ' '.self::norm($text).' ';
                foreach (self::$keys as $k) {
                    if (str_contains($t, ' '.$k.' ')) {
                        $hit = [$k, 'name'];
                        break 2;
                    }
                }
            }
        }
        if (! $hit) {
            return null;
        }
        [$la, $lo, $reg] = self::$places[$hit[0]];
        $h = hexdec(substr(md5($seed.$hit[0]), 0, 8)) / 0xFFFFFFFF;
        $ang = $h * 6.2832;
        $rad = 0.010 + 0.022 * (hexdec(substr(md5($seed.'r'), 0, 4)) / 0xFFFF);

        return ['lat' => round($la + $rad * sin($ang), 6), 'lon' => round($lo + $rad * cos($ang), 6), 'region' => $reg, 'commune' => $commune ?? ucwords($hit[0]), 'quality' => $hit[1]];
    }

    /** Número desde texto de planilla: «1.234,5», «1234.5», «$ 12.000». */
    public static function number(mixed $v): ?float
    {
        $s = trim(str_replace(['$', ' ', "\u{a0}"], '', (string) $v));
        if ($s === '' || ! preg_match('/^-?[\d.,]+$/', $s)) {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));          // 1.234,5
        } elseif (str_contains($s, ',')) {
            $s = str_replace(',', '.', $s);                                 // 12,5
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);                                  // 12.000 (miles)
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
