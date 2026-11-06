<?php

namespace App\FieldAudit;

use App\Imports\Cast;
use App\Imports\SpreadsheetReader;
use App\Models\FieldAuditTemplate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Plantillas de auditoría en Excel: carga masiva (leer) y descarga (escribir) con el mismo formato.
 *
 * Hojas: «Preguntas» (una fila por pregunta, las filas consecutivas con la misma sección forman la sección),
 * «Secciones» (peso y condición de la sección, opcional), «Plantilla» (nombre, descripción, puntaje) e «Instrucciones».
 * Las preguntas se identifican con un «Código» (P1, P2… si se deja vacío) para poder referenciarlas en las condiciones.
 */
class TemplateExcel
{
    public const MAX_CONDITIONS = 3;

    private const TYPE_LABELS = [
        'yes_no' => 'Sí / No / N/A', 'scale' => 'Escala', 'choice' => 'Selección única', 'multi_choice' => 'Selección múltiple',
        'number' => 'Número', 'text' => 'Texto', 'date' => 'Fecha', 'photo' => 'Foto', 'signature' => 'Firma', 'gps' => 'Ubicación GPS',
    ];
    private const TYPE_ALIASES = [
        'yes_no' => ['si no n a', 'si no', 'sino', 'yes no', 'si no na'], 'scale' => ['escala', 'scale', 'nota'], 'choice' => ['seleccion unica', 'unica', 'choice', 'seleccion'],
        'multi_choice' => ['seleccion multiple', 'multiple', 'multi choice'], 'number' => ['numero', 'number', 'numerico'], 'text' => ['texto', 'text', 'respuesta abierta'],
        'date' => ['fecha', 'date'], 'photo' => ['foto', 'photo', 'fotografia'], 'signature' => ['firma', 'signature'], 'gps' => ['ubicacion gps', 'gps', 'ubicacion'],
    ];
    private const SEVERITY = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Crítica'];
    private const SEVERITY_ALIASES = ['low' => ['baja', 'low'], 'medium' => ['media', 'medium'], 'high' => ['alta', 'high'], 'critical' => ['critica', 'critical']];
    private const PHOTO = ['none' => 'Sin foto', 'optional' => 'Opcional', 'required' => 'Obligatoria', 'on_fail' => 'Obligatoria si hay hallazgo'];
    private const PHOTO_ALIASES = [
        'none' => ['', 'sin foto', 'ninguna', 'no'], 'optional' => ['opcional', 'foto opcional'], 'required' => ['obligatoria', 'foto obligatoria', 'si'],
        'on_fail' => ['obligatoria si hay hallazgo', 'si hay hallazgo', 'si falla', 'obligatoria si falla'],
    ];
    private const OPS = ['equals' => 'es', 'not_equals' => 'no es', 'any_of' => 'es alguna de'];
    private const OP_ALIASES = ['equals' => ['es', 'igual', 'equals', ''], 'not_equals' => ['no es', 'distinto', 'no igual', 'not equals'], 'any_of' => ['es alguna de', 'alguna de', 'es uno de', 'any of']];

    /** Columnas de la hoja «Preguntas»: clave => [encabezado, alias normalizados (el encabezado normalizado empieza con alguno)]. */
    private static function columns(): array
    {
        $cols = [
            'section' => ['Sección', ['seccion']], 'code' => ['Código', ['codigo']], 'text' => ['Pregunta', ['pregunta']], 'type' => ['Tipo de respuesta', ['tipo']],
            'required' => ['Obligatoria', ['obligatoria']], 'help' => ['Ayuda para el auditor', ['ayuda']], 'options' => ['Opciones (separadas por ;)', ['opciones']],
            'scale' => ['Escala (ej. 1-5)', ['escala']], 'unit' => ['Unidad (números)', ['unidad']], 'range' => ['Rango aceptable (números, ej. 18-24)', ['rango']],
            'finding' => ['Genera hallazgo cuando', ['genera hallazgo']], 'severity' => ['Severidad del hallazgo', ['severidad']], 'photo' => ['Foto como evidencia', ['foto como']],
            'comment' => ['Exigir comentario si hay hallazgo', ['exigir comentario', 'comentario']], 'weight' => ['Peso (solo con puntaje)', ['peso']],
        ];
        for ($i = 1; $i <= self::MAX_CONDITIONS; $i++) {
            $cols["c{$i}q"] = ["Condición {$i} · pregunta (código)", ["condicion {$i} pregunta"]];
            $cols["c{$i}op"] = ["Condición {$i} · operador", ["condicion {$i} operador"]];
            $cols["c{$i}v"] = ["Condición {$i} · respuesta", ["condicion {$i} respuesta"]];
        }
        $cols['cmode'] = ['Condiciones: se cumple', ['condiciones se cumple', 'condiciones']];
        $cols['notice'] = ['Aviso', ['aviso']];

        return $cols;
    }

    private static function sectionColumns(): array
    {
        return ['title' => ['Sección', ['seccion']], 'weight' => ['Peso (solo con puntaje)', ['peso']], 'cq' => ['Condición · pregunta (código)', ['condicion pregunta']],
            'cop' => ['Condición · operador', ['condicion operador']], 'cv' => ['Condición · respuesta', ['condicion respuesta']]];
    }

    // ═════════════════════════════════════ LECTURA ═════════════════════════════════════

    /** @return array{ok:bool, errors:array, warnings:array, meta:array, structure:?array, summary:array} */
    public static function parse(string $path): array
    {
        $errors = [];
        $warnings = [];
        $err = function (string $sheet, ?int $row, ?string $col, string $msg) use (&$errors) {
            if (count($errors) < 300) {
                $errors[] = ['sheet' => $sheet, 'row' => $row, 'column' => $col, 'message' => $msg];
            }
        };

        try {
            $book = SpreadsheetReader::workbook($path);
        } catch (\Throwable) {
            $err('', null, null, 'No se pudo leer el archivo. Debe ser un Excel (.xlsx).');

            return self::result($errors, $warnings, [], null);
        }
        $q = $book['preguntas'] ?? null;
        if (! $q) {
            $err('', null, null, 'No se encontró la hoja «Preguntas». Descargue el archivo de ejemplo y úselo como base.');

            return self::result($errors, $warnings, [], null);
        }

        $meta = self::readMeta($book['plantilla']['rows'] ?? [], $err);
        $sectionDefs = self::readSections($book['secciones']['rows'] ?? [], $err);
        $rows = $q['rows'];
        $headerRow = null;
        foreach ($rows as $n => $cells) {
            if (array_filter($cells, fn ($c) => $c !== '') !== []) {
                $headerRow = $n;
                break;
            }
        }
        if ($headerRow === null) {
            $err('Preguntas', null, null, 'La hoja «Preguntas» está vacía.');

            return self::result($errors, $warnings, $meta, null);
        }
        $map = self::mapHeaders($rows[$headerRow], self::columns());
        foreach (['section', 'text', 'type'] as $need) {
            if (! isset($map[$need])) {
                $err('Preguntas', $headerRow, null, 'Falta la columna «'.self::columns()[$need][0].'».');
            }
        }
        if ($errors) {
            return self::result($errors, $warnings, $meta, null);
        }

        $sections = [];            // nombre normalizado => índice
        $raw = [];                 // estructura sin normalizar
        $rowOf = [];               // "si.qi" => fila de Excel
        $codes = [];               // código normalizado => ['id', 'type', 'options' (label norm => id), 'section', 'order']
        $order = 0;
        $lastSection = null;
        $pending = [];             // condiciones a resolver una vez conocidos todos los códigos

        foreach ($rows as $n => $cells) {
            if ($n <= $headerRow || array_filter($cells, fn ($c) => $c !== '') === []) {
                continue;
            }
            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';

            $sectionName = $get('section') !== '' ? $get('section') : $lastSection;
            if ($sectionName === null) {
                $err('Preguntas', $n, 'Sección', 'La primera pregunta necesita una sección.');
                continue;
            }
            $lastSection = $sectionName;
            $sk = Cast::norm($sectionName);
            if (! isset($sections[$sk])) {
                $sections[$sk] = count($raw);
                $def = $sectionDefs[$sk] ?? null;
                $raw[] = ['id' => 's_'.Str::lower(Str::random(8)), 'title' => $sectionName, 'weight' => $def['weight'] ?? 1, 'show_if' => null, 'questions' => []];
                $rowOf['s'.$sections[$sk]] = $n;
            }
            $si = $sections[$sk];

            $type = self::lookup($get('type'), self::TYPE_ALIASES);
            if ($type === null) {
                $err('Preguntas', $n, 'Tipo de respuesta', '«'.$get('type').'» no es un tipo válido. Use: '.implode(', ', self::TYPE_LABELS).'.');
                $type = 'text';
            }
            if ($get('text') === '') {
                $err('Preguntas', $n, 'Pregunta', 'El texto de la pregunta es obligatorio.');
            }

            $order++;
            $code = $get('code') !== '' ? $get('code') : 'P'.$order;
            $ck = Cast::norm($code);
            if (isset($codes[$ck])) {
                $err('Preguntas', $n, 'Código', "El código «{$code}» está repetido (fila {$codes[$ck]['row']}).");
            }

            $question = ['id' => 'q_'.Str::lower(Str::random(8)), 'text' => $get('text'), 'help' => $get('help') ?: null, 'type' => $type, 'severity' => 'medium',
                'required' => self::bool($get('required'), true), 'weight' => 1, 'evidence' => ['photo' => 'none', 'comment_on_fail' => false]];
            if ($get('weight') !== '') {
                is_numeric(str_replace(',', '.', $get('weight'))) ? $question['weight'] = (float) str_replace(',', '.', $get('weight')) : $err('Preguntas', $n, 'Peso', 'El peso debe ser un número.');
            }
            if ($get('severity') !== '') {
                ($sev = self::lookup($get('severity'), self::SEVERITY_ALIASES)) ? $question['severity'] = $sev : $err('Preguntas', $n, 'Severidad', 'Use Baja, Media, Alta o Crítica.');
            }
            if (! in_array($type, QuestionTypes::EVIDENCE, true)) {
                $photo = self::lookup($get('photo'), self::PHOTO_ALIASES);
                $photo === null && $get('photo') !== '' && $err('Preguntas', $n, 'Foto como evidencia', 'Use Sin foto, Opcional, Obligatoria u Obligatoria si hay hallazgo.');
                $question['evidence'] = ['photo' => $photo ?? 'none', 'comment_on_fail' => self::bool($get('comment'), false)];
            }

            $optionIds = [];
            switch ($type) {
                case 'yes_no':
                    $question['finding_on'] = [];
                    foreach (preg_split('/[;,|]+/', $get('finding')) as $t) {
                        $t = Cast::norm($t);
                        if ($t === '') {
                            continue;
                        }
                        $v = ['si' => 'yes', 'yes' => 'yes', 'no' => 'no', 'n a' => 'na', 'na' => 'na', 'no aplica' => 'na'][$t] ?? null;
                        $v ? $question['finding_on'][] = $v : $err('Preguntas', $n, 'Genera hallazgo cuando', "«{$t}»: use Sí, No y/o N/A.");
                    }
                    break;
                case 'scale':
                    $min = 1;
                    $max = 5;
                    if ($get('scale') !== '') {
                        preg_match('/^(\d+)\s*(?:-|–|—|a)\s*(\d+)$/u', $get('scale'), $m) ? [$min, $max] = [(int) $m[1], (int) $m[2]] : $err('Preguntas', $n, 'Escala', 'Escriba la escala como «1-5».');
                    }
                    $question['scale'] = ['min' => $min, 'max' => $max];
                    $f = $get('finding');
                    if ($f !== '') {
                        preg_match('/-?\d+/', $f, $m) ? $question['finding_at_or_below'] = (int) $m[0] : $err('Preguntas', $n, 'Genera hallazgo cuando', 'En una escala, escriba la nota máxima que genera hallazgo (ej. 2).');
                    }
                    break;
                case 'choice':
                case 'multi_choice':
                    $question['options'] = [];
                    foreach (preg_split('/[;\n|]+/', $get('options')) as $opt) {
                        $opt = trim($opt);
                        if ($opt === '') {
                            continue;
                        }
                        preg_match('/^(.*?)\s*(?:=\s*(\d+(?:[.,]\d+)?))?\s*(!)?\s*$/u', $opt, $m);
                        $label = trim($m[1] ?? $opt);
                        $oid = 'o_'.Str::lower(Str::random(8));
                        $optionIds[Cast::norm($label)] = $oid;
                        $question['options'][] = ['id' => $oid, 'label' => $label, 'score' => isset($m[2]) && $m[2] !== '' ? (float) str_replace(',', '.', $m[2]) : null, 'finding' => ! empty($m[3])];
                    }
                    if (count($question['options']) < 2) {
                        $err('Preguntas', $n, 'Opciones', 'Escriba al menos dos opciones separadas por «;». Marque con «!» las que generan hallazgo.');
                    }
                    break;
                case 'number':
                    $rng = self::range($get('range'));
                    $rng === false && $err('Preguntas', $n, 'Rango aceptable', 'Use «18-24», «>=18» o «<=24».');
                    $question['number'] = ['unit' => $get('unit') ?: null, 'min' => null, 'max' => null, 'pass_min' => $rng[0] ?? null, 'pass_max' => $rng[1] ?? null];
                    break;
            }

            $qi = count($raw[$si]['questions']);
            $raw[$si]['questions'][] = $question;
            $rowOf["{$si}.{$qi}"] = $n;
            $codes[$ck] = ['id' => $question['id'], 'type' => $type, 'options' => $optionIds, 'scale' => $question['scale'] ?? null, 'row' => $n, 'order' => $order, 'section' => $si, 'code' => $code];

            $pending[] = ['kind' => 'q', 'si' => $si, 'qi' => $qi, 'row' => $n, 'order' => $order, 'cells' => [
                'mode' => $get('cmode'), 'list' => array_map(fn ($i) => [$get("c{$i}q"), $get("c{$i}op"), $get("c{$i}v")], range(1, self::MAX_CONDITIONS)),
            ]];
        }

        // Condiciones de sección (hoja «Secciones»)
        foreach ($sectionDefs as $sk => $def) {
            if (! isset($sections[$sk])) {
                $warnings[] = ['sheet' => 'Secciones', 'row' => $def['row'], 'column' => 'Sección', 'message' => "La sección «{$def['title']}» no tiene preguntas en la hoja «Preguntas»; se ignora."];
                continue;
            }
            if ($def['cond'][0] !== '') {
                $pending[] = ['kind' => 's', 'si' => $sections[$sk], 'row' => $def['row'], 'order' => PHP_INT_MAX, 'cells' => ['mode' => '', 'list' => [$def['cond']]]];
            }
        }

        // Resolver condiciones con todos los códigos ya conocidos
        foreach ($pending as $p) {
            $conds = [];
            foreach ($p['cells']['list'] as [$cq, $cop, $cv]) {
                if ($cq === '' && $cop === '' && $cv === '') {
                    continue;
                }
                $sheet = $p['kind'] === 's' ? 'Secciones' : 'Preguntas';
                $ref = $codes[Cast::norm($cq)] ?? null;
                if (! $ref) {
                    $err($sheet, $p['row'], 'Condición · pregunta', "No existe la pregunta con código «{$cq}».");
                    continue;
                }
                if ($ref['order'] >= $p['order'] || ($p['kind'] === 's' && $ref['section'] >= $p['si'])) {
                    $err($sheet, $p['row'], 'Condición · pregunta', "La condición debe depender de una pregunta anterior; «{$cq}» viene después.");
                    continue;
                }
                $op = self::lookup($cop, self::OP_ALIASES);
                if ($op === null) {
                    $err($sheet, $p['row'], 'Condición · operador', 'Use «es», «no es» o «es alguna de».');
                    continue;
                }
                $vals = array_values(array_filter(array_map('trim', $op === 'any_of' ? preg_split('/[;|]+/', $cv) : [$cv]), fn ($x) => $x !== ''));
                $resolved = [];
                foreach ($vals as $v) {
                    $r = self::answerValue($ref, $v);
                    $r === null ? $err($sheet, $p['row'], 'Condición · respuesta', "«{$v}» no es una respuesta posible de la pregunta «{$ref['code']}».") : $resolved[] = $r;
                }
                if ($resolved) {
                    $conds[] = ['question' => $ref['id'], 'op' => $op, 'value' => $op === 'any_of' ? $resolved : $resolved[0]];
                }
            }
            if ($conds) {
                $cond = ['mode' => Cast::norm($p['cells']['mode']) === 'alguna' ? 'any' : 'all', 'conditions' => $conds];
                $p['kind'] === 's' ? $raw[$p['si']]['show_if'] = $cond : $raw[$p['si']]['questions'][$p['qi']]['show_if'] = $cond;
            }
        }

        if ($errors) {
            return self::result($errors, $warnings, $meta, null);
        }

        try {
            $structure = TemplateValidator::normalize(['sections' => $raw], true);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $path => $messages) {
                $row = null;
                if (preg_match('/sections\.(\d+)(?:\.questions\.(\d+))?/', $path, $m)) {
                    $row = $rowOf[isset($m[2]) ? "{$m[1]}.{$m[2]}" : "s{$m[1]}"] ?? null;
                }
                foreach ($messages as $msg) {
                    $err('Preguntas', $row, null, $msg);
                }
            }
            $structure = null;
        }

        return self::result($errors, $warnings, $meta, $structure);
    }

    private static function result(array $errors, array $warnings, array $meta, ?array $structure): array
    {
        $qs = $structure ? array_merge(...array_map(fn ($s) => $s['questions'], $structure['sections'])) : [];

        return [
            'ok' => $errors === [] && $structure !== null, 'errors' => $errors, 'warnings' => $warnings, 'meta' => $meta, 'structure' => $errors ? null : $structure,
            'summary' => $structure ? [
                'sections' => count($structure['sections']), 'questions' => count($qs), 'conditions' => count(array_filter($qs, fn ($q) => $q['show_if'])) + count(array_filter($structure['sections'], fn ($s) => $s['show_if'])),
                'finding_rules' => count(array_filter($qs, fn ($q) => ! empty($q['finding_on']) || ! empty($q['finding_at_or_below']) || array_filter($q['options'] ?? [], fn ($o) => $o['finding']) || (($q['number']['pass_min'] ?? null) !== null) || (($q['number']['pass_max'] ?? null) !== null))),
            ] : [],
        ];
    }

    private static function readMeta(array $rows, callable $err): array
    {
        $meta = ['name' => null, 'description' => null, 'scoring_enabled' => false, 'pass_threshold' => 80];
        foreach ($rows as $n => $cells) {
            $k = Cast::norm($cells[0] ?? '');
            $v = trim((string) ($cells[1] ?? ''));
            if ($k === '' || $v === '') {
                continue;
            }
            if (str_starts_with($k, 'nombre')) {
                $meta['name'] = mb_substr($v, 0, 150);
            } elseif (str_starts_with($k, 'descripcion')) {
                $meta['description'] = mb_substr($v, 0, 1000);
            } elseif (str_starts_with($k, 'calcular puntaje')) {
                $meta['scoring_enabled'] = self::bool($v, false);
            } elseif (str_starts_with($k, 'porcentaje')) {
                (is_numeric($v) && $v >= 1 && $v <= 100) ? $meta['pass_threshold'] = (int) $v : $err('Plantilla', $n, 'Porcentaje mínimo', 'Debe ser un número entre 1 y 100.');
            }
        }

        return $meta;
    }

    private static function readSections(array $rows, callable $err): array
    {
        $out = [];
        $header = null;
        $map = [];
        foreach ($rows as $n => $cells) {
            if (array_filter($cells, fn ($c) => $c !== '') === []) {
                continue;
            }
            if ($header === null) {
                $header = $n;
                $map = self::mapHeaders($cells, self::sectionColumns());
                continue;
            }
            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            $title = $get('title');
            if ($title === '') {
                continue;
            }
            $w = str_replace(',', '.', $get('weight'));
            $out[Cast::norm($title)] = ['title' => $title, 'row' => $n, 'weight' => is_numeric($w) ? (float) $w : 1, 'cond' => [$get('cq'), $get('cop'), $get('cv')]];
        }

        return $out;
    }

    /** @return array<string,int> clave => índice de columna (el encabezado normalizado debe empezar con el alias; gana el alias más largo) */
    private static function mapHeaders(array $header, array $columns): array
    {
        $map = [];
        $norm = array_map(fn ($h) => Cast::norm($h), $header);
        foreach ($columns as $key => [$label, $aliases]) {
            $best = null;
            $bestLen = -1;
            foreach ($norm as $i => $h) {
                if ($h === '' || in_array($i, $map, true)) {
                    continue;
                }
                foreach ($aliases as $a) {
                    if (($h === $a || str_starts_with($h, $a.' ')) && strlen($a) > $bestLen) {
                        $best = $i;
                        $bestLen = strlen($a);
                    }
                }
            }
            $best !== null && $map[$key] = $best;
        }

        return $map;
    }

    private static function lookup(string $value, array $aliases): ?string
    {
        $v = Cast::norm($value);
        foreach ($aliases as $key => $list) {
            if (in_array($v, $list, true)) {
                return $key;
            }
        }

        return null;
    }

    private static function bool(string $v, bool $default): bool
    {
        $v = Cast::norm($v);
        if ($v === '') {
            return $default;
        }

        return in_array($v, ['si', 's', 'x', '1', 'yes', 'true', 'verdadero'], true);
    }

    /** @return array{0:?float,1:?float}|false|null  null = sin rango; false = formato inválido */
    private static function range(string $v): array|false|null
    {
        $v = trim(str_replace(',', '.', $v));
        if ($v === '') {
            return null;
        }
        $num = '(-?\d+(?:\.\d+)?)';
        if (preg_match("/^>=\s*{$num}$/", $v, $m)) {
            return [(float) $m[1], null];
        }
        if (preg_match("/^<=\s*{$num}$/", $v, $m)) {
            return [null, (float) $m[1]];
        }
        if (preg_match("/^{$num}?\s*(?:-|–|—|\ba\b)\s*{$num}?$/u", $v, $m) && (($m[1] ?? '') !== '' || ($m[2] ?? '') !== '')) {
            return [($m[1] ?? '') !== '' ? (float) $m[1] : null, ($m[2] ?? '') !== '' ? (float) $m[2] : null];
        }

        return false;
    }

    private static function answerValue(array $ref, string $v): int|string|null
    {
        $n = Cast::norm($v);
        switch ($ref['type']) {
            case 'yes_no':
                return ['si' => 'yes', 'yes' => 'yes', 'no' => 'no', 'n a' => 'na', 'na' => 'na'][$n] ?? null;
            case 'scale':
                return ctype_digit($n) && $n >= ($ref['scale']['min'] ?? 1) && $n <= ($ref['scale']['max'] ?? 5) ? (int) $n : null;
            case 'choice':
            case 'multi_choice':
                return $ref['options'][$n] ?? null;
            default:
                return null;
        }
    }

    // ═════════════════════════════════════ ESCRITURA ═════════════════════════════════════

    /** .xlsx de una plantilla guardada. */
    public static function export(FieldAuditTemplate $t): string
    {
        return self::write($t->structure, [
            'name' => $t->name, 'description' => $t->description, 'scoring_enabled' => (bool) $t->scoring_enabled, 'pass_threshold' => $t->pass_threshold,
        ]);
    }

    /** .xlsx de ejemplo (con un árbol de decisiones) para empezar a cargar plantillas. */
    public static function sample(): string
    {
        $o = fn (string $l, ?float $s = null, bool $f = false) => ['label' => $l, 'score' => $s, 'finding' => $f];
        $structure = TemplateValidator::normalize(['sections' => [
            ['id' => 's1', 'title' => 'Fachada y exhibición', 'questions' => [
                ['id' => 'q1', 'text' => '¿Tiene exhibición?', 'type' => 'yes_no', 'help' => 'Mire la vitrina o el mostrador principal'],
                ['id' => 'q2', 'text' => 'Estado de la exhibición', 'type' => 'choice', 'options' => [$o('Bueno'), $o('Regular'), $o('Dañado', null, true)], 'severity' => 'high',
                    'evidence' => ['photo' => 'on_fail', 'comment_on_fail' => true], 'show_if' => ['conditions' => [['question' => 'q1', 'op' => 'equals', 'value' => 'yes']]]],
                ['id' => 'q3', 'text' => 'Foto de la fachada', 'type' => 'photo'],
                ['id' => 'q4', 'text' => 'Limpieza del local (1 a 5)', 'type' => 'scale', 'finding_at_or_below' => 2, 'severity' => 'low'],
            ]],
            ['id' => 's2', 'title' => 'Sin exhibición', 'show_if' => ['conditions' => [['question' => 'q1', 'op' => 'equals', 'value' => 'no']]], 'questions' => [
                ['id' => 'q5', 'text' => 'Motivo por el que no tiene exhibición', 'type' => 'text'],
            ]],
            ['id' => 's3', 'title' => 'Datos del local', 'questions' => [
                ['id' => 'q6', 'text' => 'Temperatura del local', 'type' => 'number', 'number' => ['unit' => '°C', 'pass_min' => 18, 'pass_max' => 24], 'required' => false],
                ['id' => 'q7', 'text' => 'Servicios disponibles', 'type' => 'multi_choice', 'options' => [$o('Recarga'), $o('Venta de equipos'), $o('Soporte')], 'required' => false],
                ['id' => 'q8', 'text' => 'Fecha de la última visita comercial', 'type' => 'date', 'required' => false],
                ['id' => 'q9', 'text' => 'Ubicación del local', 'type' => 'gps'],
                ['id' => 'q10', 'text' => 'Firma del encargado', 'type' => 'signature'],
            ]],
        ]], true);

        return self::write($structure, ['name' => 'Ejemplo – Auditoría de punto de venta', 'description' => 'Plantilla de ejemplo con decisiones (si no tiene exhibición, se salta el estado).', 'scoring_enabled' => false, 'pass_threshold' => 80]);
    }

    private static function write(array $structure, array $meta): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tplx');
        $writer = new Writer();
        $writer->openToFile($tmp);
        $head = (new Style())->withFontBold(true)->withFontColor(Color::WHITE)->withBackgroundColor('1F5EFF')->withShouldWrapText(true);
        $wrap = (new Style())->withShouldWrapText(true);
        $bold = (new Style())->withFontBold(true);

        // ── Preguntas
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Preguntas');
        $cols = self::columns();
        $widths = ['section' => 24, 'code' => 9, 'text' => 52, 'type' => 20, 'required' => 12, 'help' => 32, 'options' => 38, 'scale' => 14, 'unit' => 12, 'range' => 20, 'finding' => 22, 'severity' => 14, 'photo' => 24, 'comment' => 18, 'weight' => 12, 'cmode' => 16, 'notice' => 30];
        foreach (array_keys($cols) as $i => $k) {
            $sheet->setColumnWidth($widths[$k] ?? 22, $i + 1);
        }
        $sheet->setSheetView(new SheetView(freezeRow: 1, freezeColumn: 'C'));
        $writer->addRow(Row::fromValuesWithStyle(array_column($cols, 0), $head));

        $codes = [];
        $n = 0;
        foreach ($structure['sections'] as $s) {
            foreach ($s['questions'] as $q) {
                $codes[$q['id']] = ['code' => 'P'.(++$n), 'q' => $q];
            }
        }
        foreach ($structure['sections'] as $s) {
            foreach ($s['questions'] as $q) {
                $writer->addRow(Row::fromValuesWithStyle(self::questionRow($q, $s['title'], $codes, array_keys($cols)), $wrap));
            }
        }

        // ── Secciones
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Secciones');
        $sc = self::sectionColumns();
        foreach (array_keys($sc) as $i => $_) {
            $sheet->setColumnWidth([32, 12, 26, 18, 26][$i], $i + 1);
        }
        $writer->addRow(Row::fromValuesWithStyle(array_column($sc, 0), $head));
        foreach ($structure['sections'] as $s) {
            $c = $s['show_if']['conditions'][0] ?? null;
            $ref = $c ? ($codes[$c['question']] ?? null) : null;
            $writer->addRow(Row::fromValuesWithStyle([$s['title'], $s['weight'], $ref['code'] ?? '', $c ? self::OPS[$c['op']] : '', $c && $ref ? self::valueText($ref['q'], $c) : ''], $wrap));
        }

        // ── Plantilla
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Plantilla');
        $sheet->setColumnWidth(36, 1);
        $sheet->setColumnWidth(70, 2);
        $writer->addRow(Row::fromValuesWithStyle(['Campo', 'Valor'], $head));
        foreach ([['Nombre', $meta['name']], ['Descripción', $meta['description'] ?? ''], ['Calcular puntaje (Sí/No)', $meta['scoring_enabled'] ? 'Sí' : 'No'], ['Porcentaje mínimo para aprobar (solo con puntaje)', $meta['pass_threshold']]] as $r) {
            $writer->addRow(Row::fromValuesWithStyle($r, $wrap));
        }

        // ── Instrucciones
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Instrucciones');
        $sheet->setColumnWidth(34, 1);
        $sheet->setColumnWidth(100, 2);
        $writer->addRow(Row::fromValuesWithStyle(['Cómo llenar la plantilla', ''], $head));
        foreach (self::instructions() as $r) {
            $writer->addRow(Row::fromValuesWithStyle($r, $r[1] === '' ? $bold : $wrap));
        }
        $writer->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    private static function questionRow(array $q, string $section, array $codes, array $keys): array
    {
        $v = array_fill_keys($keys, '');
        $v['section'] = $section;
        $v['code'] = $codes[$q['id']]['code'];
        $v['text'] = $q['text'];
        $v['type'] = self::TYPE_LABELS[$q['type']];
        $v['required'] = $q['required'] ? 'Sí' : 'No';
        $v['help'] = $q['help'] ?? '';
        $v['severity'] = in_array($q['type'], ['yes_no', 'scale', 'choice', 'multi_choice', 'number'], true) ? self::SEVERITY[$q['severity']] : '';
        $v['weight'] = $q['weight'];
        if (! in_array($q['type'], QuestionTypes::EVIDENCE, true)) {
            $v['photo'] = self::PHOTO[$q['evidence']['photo']];
            $v['comment'] = $q['evidence']['comment_on_fail'] ? 'Sí' : 'No';
        }
        switch ($q['type']) {
            case 'yes_no':
                $v['finding'] = implode('; ', array_map(fn ($x) => ['yes' => 'Sí', 'no' => 'No', 'na' => 'N/A'][$x], $q['finding_on'] ?? []));
                break;
            case 'scale':
                $v['scale'] = $q['scale']['min'].'-'.$q['scale']['max'];
                $v['finding'] = $q['finding_at_or_below'] ?? '';
                break;
            case 'choice':
            case 'multi_choice':
                $v['options'] = implode('; ', array_map(fn ($o) => $o['label'].($o['score'] !== null ? '='.(float) $o['score'] + 0 : '').($o['finding'] ? '!' : ''), $q['options']));
                break;
            case 'number':
                $v['unit'] = $q['number']['unit'] ?? '';
                $a = $q['number']['pass_min'];
                $b = $q['number']['pass_max'];
                $v['range'] = $a !== null && $b !== null ? ($a + 0).'-'.($b + 0) : ($a !== null ? '>='.($a + 0) : ($b !== null ? '<='.($b + 0) : ''));
                break;
        }
        $conds = $q['show_if']['conditions'] ?? [];
        foreach (array_slice($conds, 0, self::MAX_CONDITIONS) as $i => $c) {
            $ref = $codes[$c['question']] ?? null;
            $v['c'.($i + 1).'q'] = $ref['code'] ?? '';
            $v['c'.($i + 1).'op'] = self::OPS[$c['op']];
            $v['c'.($i + 1).'v'] = $ref ? self::valueText($ref['q'], $c) : '';
        }
        if (count($conds) > 1) {
            $v['cmode'] = ($q['show_if']['mode'] ?? 'all') === 'any' ? 'alguna' : 'todas';
        }
        if (count($conds) > self::MAX_CONDITIONS) {
            $v['notice'] = 'Tenía más de '.self::MAX_CONDITIONS.' condiciones: solo se exportaron las primeras.';
        }

        return array_values($v);
    }

    private static function valueText(array $ref, array $cond): string
    {
        $vals = $cond['op'] === 'any_of' ? (array) $cond['value'] : [$cond['value']];
        $text = array_map(function ($x) use ($ref) {
            return match ($ref['type']) {
                'yes_no' => ['yes' => 'Sí', 'no' => 'No', 'na' => 'N/A'][$x] ?? $x,
                'choice', 'multi_choice' => collect($ref['options'])->firstWhere('id', $x)['label'] ?? $x,
                default => (string) $x,
            };
        }, $vals);

        return implode('; ', $text);
    }

    private static function instructions(): array
    {
        return [
            ['Hoja «Preguntas»', ''],
            ['Una fila = una pregunta', 'Las filas seguidas con el mismo nombre en «Sección» forman esa sección. Si deja la sección en blanco, la pregunta queda en la sección de la fila anterior.'],
            ['Código', 'Nombre corto para referirse a la pregunta en las condiciones (por ejemplo EXH). Si lo deja vacío se usa P1, P2, P3… según el orden de las filas.'],
            ['Tipo de respuesta', implode(' · ', self::TYPE_LABELS)],
            ['Obligatoria', 'Sí o No (si lo deja vacío: Sí).'],
            ['Opciones (selección única o múltiple)', 'Separe con «;». Al final de cada opción puede poner «=puntaje» (solo si calcula puntaje) y «!» si esa opción genera hallazgo. Ejemplo: Bueno; Regular; Dañado!'],
            ['Escala', 'Rango de notas, por ejemplo 1-5 (por defecto 1-5).'],
            ['Genera hallazgo cuando', 'Sí/No: escriba la respuesta que es un problema (Sí, No y/o N/A; separe con «;»). Escala: la nota máxima que genera hallazgo (por ejemplo 2 = nota 2 o menos). Selección: use «!» en las opciones. Número: use el rango aceptable. Vacío = la pregunta no genera hallazgos.'],
            ['Rango aceptable (números)', 'Fuera de este rango hay hallazgo. Ejemplos: 18-24 · >=18 · <=24. Puede indicar también la unidad (°C, kg…).'],
            ['Severidad del hallazgo', 'Baja, Media, Alta o Crítica (por defecto Media).'],
            ['Foto como evidencia', 'Sin foto · Opcional · Obligatoria · Obligatoria si hay hallazgo.'],
            ['Exigir comentario si hay hallazgo', 'Sí o No.'],
            ['Peso', 'Solo cuenta si la plantilla calcula puntaje (hoja «Plantilla»).'],
            ['Condiciones (árbol de decisiones)', ''],
            ['Mostrar solo si…', 'En «Condición 1» indique el código de una pregunta ANTERIOR, el operador (es · no es · es alguna de) y la respuesta. La pregunta solo aparecerá cuando se cumpla. Hasta 3 condiciones; en «Condiciones: se cumple» escriba «todas» o «alguna».'],
            ['Ejemplo', 'La pregunta 2 «Estado de la exhibición» tiene Condición 1: pregunta = P1, operador = es, respuesta = Sí → se salta cuando P1 es No.'],
            ['Respuestas', 'Sí/No: Sí, No o N/A · Selección: el texto de la opción · Escala: el número. Con «es alguna de» separe con «;».'],
            ['Hoja «Secciones» (opcional)', 'Peso de la sección y una condición para mostrar u ocultar la sección completa (mismo formato).'],
            ['Hoja «Plantilla» (opcional)', 'Nombre, descripción y si calcula puntaje. Si no hay nombre se usa el del archivo.'],
            ['Al cargar', 'El sistema valida todo y le muestra los errores con su fila antes de crear la plantilla. Se crea como borrador: revísela en el editor y publíquela.'],
        ];
    }
}
