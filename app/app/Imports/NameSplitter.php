<?php

namespace App\Imports;

/**
 * Separa un nombre completo en nombres, apellido paterno y apellido materno (convención chilena).
 *
 * No es adivinación ciega: decide el orden ("Nombres Apellidos" o "Apellidos Nombres") comparando cada
 * palabra contra listas de nombres de pila y apellidos frecuentes, y declara su confianza:
 *   high   = formato con coma o evidencia clara; medium = reglas razonables pero no hay certeza;
 *   low    = ambiguo: no se debe cargar sin revisión humana.
 */
class NameSplitter
{
    public const FORMATS = ['auto', 'names_first', 'surnames_first', 'comma'];

    /** Partículas que forman parte de un apellido compuesto (De la Fuente, Del Río, San Martín...). */
    private const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y', 'san', 'santa', 'van', 'von', 'da', 'di', 'mc', 'mac'];

    private static ?array $given = null;
    private static ?array $surnames = null;

    /** @return array{first_name:?string,last_name:?string,second_last_name:?string,confidence:string,format:string,note:?string} */
    public static function split(string $full, string $format = 'auto'): array
    {
        self::$given ??= require __DIR__.'/data/given_names.php';
        self::$surnames ??= require __DIR__.'/data/surnames.php';

        $full = trim(preg_replace('/\s+/u', ' ', $full));
        if ($full === '') {
            return self::result(null, null, null, 'low', 'auto', 'Vacío.');
        }

        $pretty = self::shouldRecase($full);

        // 1) "Apellidos, Nombres": lo más confiable.
        if (substr_count($full, ',') === 1 && in_array($format, ['auto', 'comma'], true)) {
            [$left, $right] = array_map('trim', explode(',', $full));
            if ($left !== '' && $right !== '') {
                [$pat, $mat, $note] = self::surnamePair(self::units($left));

                return self::result($right, $pat, $mat, $note ? 'medium' : 'high', 'comma', $note, $pretty);
            }
        }
        $full = str_replace(',', ' ', $full);
        $units = self::units($full);
        $n = count($units);

        if ($n === 1) {
            return self::result($units[0], null, null, 'low', $format, 'Una sola palabra: no hay apellidos.', $pretty);
        }

        // 2) Decidir el orden.
        [$nf, $sf] = self::score($units);
        $order = match ($format) {
            'names_first', 'surnames_first' => $format,
            default => $nf >= $sf ? 'names_first' : 'surnames_first',
        };
        $diff = abs($nf - $sf);

        // 3) Repartir las palabras según el orden.
        $res = $order === 'names_first' ? self::namesFirst($units) : self::surnamesFirst($units);
        [$names, $pat, $mat, $ambiguous] = $res;

        if ($format !== 'auto') {
            $confidence = $ambiguous ? 'low' : 'medium';        // el usuario fijó el orden; queda la ambigüedad de cuántos son nombres
            $note = $ambiguous ? 'No es claro cuántas palabras son nombres y cuántas apellidos.' : null;
        } elseif ($diff >= 2 && ! $ambiguous) {
            $confidence = 'high';
            $note = null;
        } elseif ($diff >= 1) {
            $confidence = 'medium';
            $note = $ambiguous ? 'Revise cuántas palabras son nombres y cuántas apellidos.' : null;
        } else {
            $confidence = 'low';
            $note = 'No se pudo determinar el orden (nombres/apellidos).';
        }

        return self::result($names, $pat, $mat, $confidence, $order, $note, $pretty);
    }

    /** Agrupa partículas con la palabra siguiente: "de la Fuente" es una unidad. */
    private static function units(string $text): array
    {
        $units = [];
        $buffer = [];
        foreach (explode(' ', $text) as $word) {
            if (in_array(Cast::norm($word), self::PARTICLES, true) && $word !== '') {
                $buffer[] = $word;
                continue;
            }
            $units[] = implode(' ', array_merge($buffer, [$word]));
            $buffer = [];
        }
        if ($buffer !== []) {
            $units[] = implode(' ', $buffer); // partícula suelta al final
        }

        return $units;
    }

    private static function lastWord(string $unit): string
    {
        $w = explode(' ', Cast::norm($unit));

        return end($w);
    }

    private static function isGiven(string $unit): bool
    {
        return isset(self::$given[self::lastWord($unit)]);
    }

    private static function isSurname(string $unit): bool
    {
        $norm = Cast::norm($unit);

        return isset(self::$surnames[$norm]) || isset(self::$surnames[self::lastWord($unit)])
            || (in_array(explode(' ', $norm)[0], ['de', 'del', 'la', 'san', 'santa', 'van', 'von', 'mc', 'mac', 'da', 'di'], true) && ! self::isGiven($unit));
    }

    /** @return array{0:int,1:int} puntaje para "nombres primero" y "apellidos primero" */
    private static function score(array $u): array
    {
        $n = count($u);
        $nf = (int) self::isGiven($u[0]) + (int) self::isSurname($u[$n - 1]) + ($n >= 3 ? (int) self::isSurname($u[$n - 2]) : 0);
        $sf = (int) self::isSurname($u[0]) + (int) self::isGiven($u[$n - 1]) + ($n >= 3 ? (int) self::isSurname($u[1]) : 0);

        return [$nf, $sf];
    }

    /** "Ana María Pérez Soto": últimos dos = apellidos, salvo que el penúltimo sea claramente un nombre. */
    private static function namesFirst(array $u): array
    {
        $n = count($u);
        if ($n === 2) {
            return [$u[0], $u[1], null, false];
        }
        $isOneSurname = self::isGiven($u[$n - 2]) && ! self::isSurname($u[$n - 2]);
        if ($isOneSurname) {
            return [implode(' ', array_slice($u, 0, $n - 1)), $u[$n - 1], null, false];
        }
        // 3 palabras: ¿1 nombre + 2 apellidos, o 2 nombres + 1 apellido? Sin evidencia, es ambiguo.
        $ambiguous = $n === 3 && ! self::isSurname($u[1]);

        return [implode(' ', array_slice($u, 0, $n - 2)), $u[$n - 2], $u[$n - 1], $ambiguous || $n >= 6];
    }

    /** "Pérez Soto Ana María": primeros dos = apellidos. */
    private static function surnamesFirst(array $u): array
    {
        $n = count($u);
        if ($n === 2) {
            return [$u[1], $u[0], null, false];
        }
        $isOneSurname = self::isGiven($u[1]) && ! self::isSurname($u[1]);
        if ($isOneSurname) {
            return [implode(' ', array_slice($u, 1)), $u[0], null, false];
        }
        $ambiguous = $n === 3 && ! self::isSurname($u[1]);

        return [implode(' ', array_slice($u, 2)), $u[0], $u[1], $ambiguous || $n >= 6];
    }

    /** Lado de apellidos de "Apellidos, Nombres". @return array{0:?string,1:?string,2:?string} paterno, materno, nota */
    private static function surnamePair(array $units): array
    {
        return match (count($units)) {
            0 => [null, null, null],
            1 => [$units[0], null, null],
            2 => [$units[0], $units[1], null],
            default => [$units[0], implode(' ', array_slice($units, 1)), 'Más de dos apellidos: revise la separación.'],
        };
    }

    /** Si todo viene en mayúsculas o minúsculas, se pasa a "Título"; si ya tiene mayúsculas mixtas se respeta. */
    private static function shouldRecase(string $s): bool
    {
        return $s === mb_strtoupper($s) || $s === mb_strtolower($s);
    }

    private static function title(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        $words = explode(' ', mb_strtolower($s));
        foreach ($words as $i => $w) {
            $words[$i] = ($i > 0 && in_array(Cast::norm($w), ['de', 'del', 'la', 'las', 'los', 'y', 'e'], true)) ? $w : mb_convert_case($w, MB_CASE_TITLE);
        }

        return implode(' ', $words);
    }

    private static function result(?string $names, ?string $pat, ?string $mat, string $confidence, string $format, ?string $note, bool $recase = false): array
    {
        $fix = fn (?string $v) => $recase ? self::title($v) : ($v === '' ? null : $v);

        return ['first_name' => $fix($names), 'last_name' => $fix($pat), 'second_last_name' => $fix($mat), 'confidence' => $confidence, 'format' => $format, 'note' => $note];
    }
}
