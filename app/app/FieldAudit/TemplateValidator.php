<?php

namespace App\FieldAudit;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Valida y normaliza la estructura de una plantilla (secciones y preguntas): asigna ids estables, completa valores
 * por defecto y rechaza lo inconsistente. En borrador se tolera una plantilla incompleta; al publicar ($strict) no.
 *
 * Condiciones (árbol de decisiones): una sección o pregunta puede llevar `show_if`:
 *   { mode: 'all'|'any', conditions: [ { question: <id anterior>, op: 'equals'|'not_equals'|'any_of', value: ... } ] }
 * Solo se puede depender de preguntas ANTERIORES (así no hay ciclos). Se acepta también el formato antiguo {question, equals}.
 */
class TemplateValidator
{
    private const MAX_SECTIONS = 30;
    private const MAX_QUESTIONS = 300;
    private const MAX_CONDITIONS = 5;
    /** Tipos que pueden controlar una condición. */
    public const CONTROL_TYPES = ['yes_no', 'choice', 'multi_choice', 'scale'];

    /** @return array{sections: array} */
    public static function normalize(mixed $structure, bool $strict): array
    {
        $errors = [];
        $err = function (string $path, string $msg) use (&$errors) { $errors["structure.$path"][] = $msg; };

        $sections = is_array($structure['sections'] ?? null) ? array_values($structure['sections']) : [];
        if (count($sections) > self::MAX_SECTIONS) {
            $err('sections', 'Máximo '.self::MAX_SECTIONS.' secciones.');
        }
        if ($strict && $sections === []) {
            $err('sections', 'Agregue al menos una sección con preguntas.');
        }

        $usedIds = [];
        $known = [];          // id de pregunta anterior => su definición normalizada (para validar condiciones)
        $out = [];
        $total = 0;

        foreach ($sections as $si => $s) {
            $s = is_array($s) ? $s : [];
            $title = trim((string) ($s['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 150) {
                $err("sections.$si.title", 'El título de la sección es obligatorio (máx. 150 caracteres).');
            }
            $questions = is_array($s['questions'] ?? null) ? array_values($s['questions']) : [];
            if ($strict && $questions === []) {
                $err("sections.$si.questions", 'La sección debe tener al menos una pregunta.');
            }
            $sectionCond = self::condition($s['show_if'] ?? null, $known, "sections.$si.show_if", $err);

            $qs = [];
            foreach ($questions as $qi => $q) {
                $total++;
                $p = "sections.$si.questions.$qi";
                $q = is_array($q) ? $q : [];
                $id = self::uniqueId($q['id'] ?? null, 'q', $usedIds);

                $text = trim((string) ($q['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 500) {
                    $err("$p.text", 'El texto de la pregunta es obligatorio (máx. 500 caracteres).');
                }
                $type = (string) ($q['type'] ?? '');
                if (! in_array($type, QuestionTypes::ALL, true)) {
                    $err("$p.type", 'Tipo de pregunta no válido.');
                    $type = 'text';
                }

                // "critical" (formato anterior) pasa a ser solo la severidad del hallazgo.
                $severity = in_array($q['severity'] ?? null, QuestionTypes::SEVERITIES, true) ? $q['severity'] : 'medium';
                if (! empty($q['critical'])) {
                    $severity = 'critical';
                }
                $norm = [
                    'id' => $id, 'text' => $text, 'help' => self::str($q['help'] ?? null, 500), 'type' => $type,
                    'required' => (bool) ($q['required'] ?? true), 'weight' => self::number($q['weight'] ?? 1, 0.1, 10, 1),
                    'severity' => $severity, 'evidence' => ['photo' => 'none', 'comment_on_fail' => false], 'show_if' => null,
                ];

                if (! in_array($type, QuestionTypes::EVIDENCE, true)) {
                    $photo = $q['evidence']['photo'] ?? 'none';
                    $norm['evidence'] = ['photo' => in_array($photo, QuestionTypes::PHOTO_RULES, true) ? $photo : 'none', 'comment_on_fail' => (bool) ($q['evidence']['comment_on_fail'] ?? false)];
                }

                if ($type === 'yes_no') {
                    $on = array_values(array_intersect(['yes', 'no', 'na'], is_array($q['finding_on'] ?? null) ? $q['finding_on'] : []));   // explícito: solo lo que el diseñador marca
                    $norm['finding_on'] = $on;
                }
                if (in_array($type, ['choice', 'multi_choice'], true)) {
                    $norm['options'] = self::options($q['options'] ?? [], "$p.options", $err);
                }
                if ($type === 'scale') {
                    $min = (int) ($q['scale']['min'] ?? 1);
                    $max = (int) ($q['scale']['max'] ?? 5);
                    if ($min < 0 || $max > 10 || $max <= $min) {
                        $err("$p.scale", 'La escala debe ir de un mínimo (≥ 0) a un máximo (≤ 10) mayor.');
                        [$min, $max] = [1, 5];
                    }
                    $norm['scale'] = ['min' => $min, 'max' => $max];
                    $at = $q['finding_at_or_below'] ?? null;
                    $norm['finding_at_or_below'] = (is_numeric($at) && $at >= $min && $at <= $max) ? (int) $at : null;
                }
                if ($type === 'number') {
                    $norm['number'] = [
                        'unit' => self::str($q['number']['unit'] ?? null, 20),
                        'min' => is_numeric($q['number']['min'] ?? null) ? (float) $q['number']['min'] : null,
                        'max' => is_numeric($q['number']['max'] ?? null) ? (float) $q['number']['max'] : null,
                        'pass_min' => is_numeric($q['number']['pass_min'] ?? null) ? (float) $q['number']['pass_min'] : null,   // rango aceptable:
                        'pass_max' => is_numeric($q['number']['pass_max'] ?? null) ? (float) $q['number']['pass_max'] : null,   // fuera de él hay hallazgo
                    ];
                }
                $norm['show_if'] = self::condition($q['show_if'] ?? null, $known, "$p.show_if", $err);

                $known[$id] = $norm;
                $qs[] = $norm;
            }
            $out[] = ['id' => self::uniqueId($s['id'] ?? null, 's', $usedIds), 'title' => $title, 'weight' => self::number($s['weight'] ?? 1, 0.1, 10, 1), 'show_if' => $sectionCond, 'questions' => $qs];
        }
        if ($total > self::MAX_QUESTIONS) {
            $err('sections', 'Máximo '.self::MAX_QUESTIONS.' preguntas por plantilla.');
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['sections' => $out];
    }

    /** Normaliza una condición (formato nuevo o antiguo) y valida que dependa de preguntas anteriores y de valores posibles. */
    private static function condition(mixed $raw, array $known, string $path, callable $err): ?array
    {
        if (empty($raw) || ! is_array($raw)) {
            return null;
        }
        if (isset($raw['question'])) {   // formato antiguo: una sola condición de igualdad
            $raw = ['mode' => 'all', 'conditions' => [['question' => $raw['question'], 'op' => 'equals', 'value' => $raw['equals'] ?? null]]];
        }
        $list = is_array($raw['conditions'] ?? null) ? array_values($raw['conditions']) : [];
        if ($list === []) {
            return null;
        }
        if (count($list) > self::MAX_CONDITIONS) {
            $err($path, 'Máximo '.self::MAX_CONDITIONS.' condiciones.');
            $list = array_slice($list, 0, self::MAX_CONDITIONS);
        }

        $out = [];
        foreach ($list as $i => $c) {
            $c = is_array($c) ? $c : [];
            $ref = $known[(string) ($c['question'] ?? '')] ?? null;
            if (! $ref) {
                $err($path, 'Cada condición debe depender de una pregunta anterior.');
                continue;
            }
            if (! in_array($ref['type'], self::CONTROL_TYPES, true)) {
                $err($path, 'Una condición solo puede depender de preguntas de Sí/No, escala o selección.');
                continue;
            }
            $op = in_array($c['op'] ?? 'equals', ['equals', 'not_equals', 'any_of'], true) ? ($c['op'] ?? 'equals') : 'equals';
            $value = $c['value'] ?? null;
            $valid = match ($ref['type']) {
                'yes_no' => ['yes', 'no', 'na'],
                'choice', 'multi_choice' => array_column($ref['options'] ?? [], 'id'),
                default => range($ref['scale']['min'] ?? 1, $ref['scale']['max'] ?? 5),
            };
            $values = $op === 'any_of' ? (is_array($value) ? array_values($value) : [$value]) : [$value];
            $values = array_map(fn ($v) => $ref['type'] === 'scale' && is_numeric($v) ? (int) $v : $v, $values);
            if ($values === [] || array_diff($values, $valid) !== []) {
                $err($path, 'Elija una respuesta válida para la condición.');
                continue;
            }
            $out[] = ['question' => $ref['id'], 'op' => $op, 'value' => $op === 'any_of' ? $values : $values[0]];
        }

        return $out === [] ? null : ['mode' => ($raw['mode'] ?? 'all') === 'any' ? 'any' : 'all', 'conditions' => $out];
    }

    private static function options(mixed $options, string $path, callable $err): array
    {
        $out = [];
        $used = [];
        foreach (is_array($options) ? array_values($options) : [] as $i => $o) {
            $label = trim((string) ($o['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 150) {
                $err("$path.$i.label", 'Cada opción necesita un texto (máx. 150 caracteres).');
            }
            $score = $o['score'] ?? null;
            if ($score !== null && $score !== '' && (! is_numeric($score) || $score < 0 || $score > 100)) {
                $err("$path.$i.score", 'El puntaje de la opción debe estar entre 0 y 100.');
                $score = null;
            }
            $out[] = ['id' => self::uniqueId($o['id'] ?? null, 'o', $used), 'label' => $label, 'score' => ($score === null || $score === '') ? null : (float) $score, 'finding' => (bool) ($o['finding'] ?? false)];
        }
        if (count($out) < 2) {
            $err($path, 'Agregue al menos dos opciones.');
        }

        return $out;
    }

    private static function uniqueId(mixed $given, string $prefix, array &$used): string
    {
        $id = is_string($given) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $given) && ! isset($used[$given]) ? $given : $prefix.'_'.Str::lower(Str::random(8));
        $used[$id] = true;

        return $id;
    }

    private static function number(mixed $v, float $min, float $max, float $default): float
    {
        return is_numeric($v) ? max($min, min($max, (float) $v)) : $default;
    }

    private static function str(mixed $v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
