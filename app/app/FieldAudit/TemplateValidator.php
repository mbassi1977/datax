<?php

namespace App\FieldAudit;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Valida y normaliza la estructura de una plantilla (secciones y preguntas): asigna ids estables, completa valores
 * por defecto y rechaza lo inconsistente. En borrador se tolera una plantilla incompleta; al publicar ($strict) no.
 */
class TemplateValidator
{
    private const MAX_SECTIONS = 30;
    private const MAX_QUESTIONS = 300;

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
        $order = [];          // id de pregunta => posición global (para validar condiciones "mostrar si")
        $out = [];
        $total = 0;

        foreach ($sections as $si => $s) {
            $s = is_array($s) ? $s : [];
            $title = trim((string) ($s['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 150) {
                $err("sections.$si.title", 'El título de la sección es obligatorio (máx. 150 caracteres).');
            }
            $weight = self::number($s['weight'] ?? 1, 0.1, 10, 1);
            $questions = is_array($s['questions'] ?? null) ? array_values($s['questions']) : [];
            if ($strict && $questions === []) {
                $err("sections.$si.questions", 'La sección debe tener al menos una pregunta.');
            }

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

                $norm = [
                    'id' => $id, 'text' => $text, 'help' => self::str($q['help'] ?? null, 500), 'type' => $type,
                    'required' => (bool) ($q['required'] ?? true), 'weight' => self::number($q['weight'] ?? 1, 0.1, 10, 1),
                    'critical' => (bool) ($q['critical'] ?? false),
                    'severity' => in_array($q['severity'] ?? null, QuestionTypes::SEVERITIES, true) ? $q['severity'] : 'medium',
                    'evidence' => ['photo' => 'none', 'comment_on_fail' => false],
                    'show_if' => null,
                ];
                if ($norm['critical']) {
                    $norm['severity'] = 'critical';
                }

                if (! in_array($type, QuestionTypes::EVIDENCE, true)) {
                    $photo = $q['evidence']['photo'] ?? 'none';
                    $norm['evidence'] = ['photo' => in_array($photo, QuestionTypes::PHOTO_RULES, true) ? $photo : 'none', 'comment_on_fail' => (bool) ($q['evidence']['comment_on_fail'] ?? false)];
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
                }
                if ($type === 'number') {
                    $norm['number'] = [
                        'unit' => self::str($q['number']['unit'] ?? null, 20),
                        'min' => is_numeric($q['number']['min'] ?? null) ? (float) $q['number']['min'] : null,
                        'max' => is_numeric($q['number']['max'] ?? null) ? (float) $q['number']['max'] : null,
                        'pass_min' => is_numeric($q['number']['pass_min'] ?? null) ? (float) $q['number']['pass_min'] : null,
                        'pass_max' => is_numeric($q['number']['pass_max'] ?? null) ? (float) $q['number']['pass_max'] : null,
                    ];
                }

                if (! empty($q['show_if']) && is_array($q['show_if'])) {
                    $ref = (string) ($q['show_if']['question'] ?? '');
                    if (! isset($order[$ref])) {
                        $err("$p.show_if", 'La condición debe depender de una pregunta anterior.');
                    } else {
                        $norm['show_if'] = ['question' => $ref, 'equals' => $q['show_if']['equals'] ?? null];
                    }
                }

                $order[$id] = true;
                $qs[] = $norm;
            }
            $out[] = ['id' => self::uniqueId($s['id'] ?? null, 's', $usedIds), 'title' => $title, 'weight' => $weight, 'questions' => $qs];
        }
        if ($total > self::MAX_QUESTIONS) {
            $err('sections', 'Máximo '.self::MAX_QUESTIONS.' preguntas por plantilla.');
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['sections' => $out];
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
            $out[] = ['id' => self::uniqueId($o['id'] ?? null, 'o', $used), 'label' => $label, 'score' => ($score === null || $score === '') ? null : (float) $score];
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
