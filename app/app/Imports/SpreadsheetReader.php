<?php

namespace App\Imports;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/** Lee CSV (detecta separador y codificación) y XLSX (primera hoja). Devuelve filas como arrays de texto. */
class SpreadsheetReader
{
    public const MAX_ROWS = 5000;

    public static function extension(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new RuntimeException('Formato no soportado. Use .xlsx o .csv.');
        }

        return $ext === 'txt' ? 'csv' : $ext;
    }

    /** @return Generator<int, array<int, string>> clave = número de fila del archivo (1 = encabezado) */
    public static function rows(string $path, string $ext): Generator
    {
        return $ext === 'xlsx' ? self::xlsx($path) : self::csv($path);
    }

    private static function csv(string $path): Generator
    {
        $raw = file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); // Excel en español guarda así
        }
        $firstLine = strtok($raw, "\r\n") ?: '';
        $delimiter = collect([';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")])
            ->sortDesc()->keys()->first();

        $tmp = tempnam(sys_get_temp_dir(), 'hrxcsv');
        file_put_contents($tmp, $raw);
        try {
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: $delimiter));
            $reader->open($tmp);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $i => $row) {
                    yield $i => self::cells($row->toArray());
                }
                break;
            }
            $reader->close();
        } finally {
            @unlink($tmp);
        }
    }

    private static function xlsx(string $path): Generator
    {
        $reader = new XlsxReader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $i => $row) {
                    yield $i => self::cells($row->toArray());
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private static function cells(array $cells): array
    {
        return array_map(function ($v) {
            if ($v instanceof DateTimeInterface) {
                return $v->format('Y-m-d');
            }
            if (is_float($v) && floor($v) === $v) {
                return (string) (int) $v;  // 1234.0 -> 1234
            }

            return trim((string) $v);
        }, array_values($cells));
    }
}
