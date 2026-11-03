<?php

namespace App\FieldAudit;

/**
 * Puntaje y completitud de una auditoría. Es una función pura de (estructura, respuestas, evidencias):
 *  - yes_no: Sí=100, No=0, N/A se excluye · scale: proporción dentro del rango · choice/multi_choice: puntaje de la opción
 *  - number: 100/0 si la plantilla define un rango aceptable · el resto no puntúa
 *  - Una pregunta "falla" bajo 50 puntos; si además es crítica, la auditoría falla sin importar el puntaje global.
 *  - Sección = promedio ponderado de sus preguntas; total = promedio ponderado de las secciones.
 */
class Scorer
{
    public const FAIL_BELOW = 50.0;

    /**
     * @param array<string, array{value?: mixed, comment?: ?string}> $answers  por id de pregunta
     * @param array<string, array{photo?: int, signature?: int}> $evidence      conteo por id de pregunta
     */
    public static function evaluate(array $structure, array $answers, array $evidence, int $passThreshold = 80): array
    {
        $sections = [];
        $missing = [];
        $failed = [];
        $critical = 0;
        $answered = 0;
        $total = 0;
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($structure['sections'] ?? [] as $s) {
            $sSum = 0.0;
            $sWeight = 0.0;
            $sAnswered = 0;
            $sTotal = 0;

            foreach ($s['questions'] ?? [] as $q) {
                if (! self::visible($q, $answers)) {
                    continue;
                }
                $sTotal++;
                $total++;
                $a = $answers[$q['id']] ?? [];
                $value = $a['value'] ?? null;
                $isAnswered = self::answered($q, $value, $evidence[$q['id']] ?? []);
                if ($isAnswered) {
                    $sAnswered++;
                    $answered++;
                }

                $score = $isAnswered ? self::questionScore($q, $value) : null;
                if ($score !== null) {
                    $sSum += $score * $q['weight'];
                    $sWeight += $q['weight'];
                }
                $fail = $score !== null && $score < self::FAIL_BELOW;
                if ($fail) {
                    $failed[] = $q['id'];
                    $q['critical'] && $critical++;
                }

                $text = $q['text'];
                if ($q['required'] && ! $isAnswered) {
                    $missing[] = ['question_id' => $q['id'], 'reason' => 'required', 'text' => $text];
                    continue;
                }
                $photos = ($evidence[$q['id']]['photo'] ?? 0);
                $rule = $q['evidence']['photo'] ?? 'none';
                if (($rule === 'required' && $isAnswered && $photos < 1) || ($rule === 'on_fail' && $fail && $photos < 1)) {
                    $missing[] = ['question_id' => $q['id'], 'reason' => 'photo_required', 'text' => $text];
                }
                if (($q['evidence']['comment_on_fail'] ?? false) && $fail && trim((string) ($a['comment'] ?? '')) === '') {
                    $missing[] = ['question_id' => $q['id'], 'reason' => 'comment_required', 'text' => $text];
                }
            }

            $sScore = $sWeight > 0 ? round($sSum / $sWeight, 1) : null;
            if ($sScore !== null) {
                $weightedSum += $sScore * $s['weight'];
                $weightTotal += $s['weight'];
            }
            $sections[] = ['id' => $s['id'], 'title' => $s['title'], 'score' => $sScore, 'answered' => $sAnswered, 'total' => $sTotal];
        }

        $score = $weightTotal > 0 ? round($weightedSum / $weightTotal, 1) : null;

        return [
            'sections' => $sections,
            'score' => $score,
            'critical_failures' => $critical,
            'failed' => $failed,
            'missing' => $missing,
            'progress' => ['answered' => $answered, 'total' => $total],
            'result' => ($critical === 0 && ($score === null || $score >= $passThreshold)) ? 'pass' : 'fail',
        ];
    }

    /** @return array<int, array> preguntas visibles con su sección, en orden */
    public static function questions(array $structure): array
    {
        $out = [];
        foreach ($structure['sections'] ?? [] as $s) {
            foreach ($s['questions'] ?? [] as $q) {
                $out[$q['id']] = $q + ['_section_id' => $s['id'], '_section_title' => $s['title']];
            }
        }

        return $out;
    }

    public static function visible(array $q, array $answers): bool
    {
        $c = $q['show_if'] ?? null;
        if (! $c) {
            return true;
        }
        $v = $answers[$c['question']]['value'] ?? null;
        $expected = $c['equals'] ?? null;

        return is_array($v) ? in_array($expected, $v, true) : $v === $expected;
    }

    public static function answered(array $q, mixed $value, array $evidence): bool
    {
        return match ($q['type']) {
            'photo' => ($evidence['photo'] ?? 0) > 0,
            'signature' => ($evidence['signature'] ?? 0) > 0,
            'gps' => is_array($value) && isset($value['lat'], $value['lng']),
            default => ! ($value === null || $value === '' || $value === []),
        };
    }

    public static function questionScore(array $q, mixed $value): ?float
    {
        switch ($q['type']) {
            case 'yes_no':
                return match ($value) { 'yes' => 100.0, 'no' => 0.0, default => null };
            case 'scale':
                $min = $q['scale']['min'] ?? 1;
                $max = $q['scale']['max'] ?? 5;

                return is_numeric($value) ? round((max($min, min($max, (float) $value)) - $min) / ($max - $min) * 100, 1) : null;
            case 'choice':
                foreach ($q['options'] ?? [] as $o) {
                    if ($o['id'] === $value) {
                        return $o['score'];
                    }
                }

                return null;
            case 'multi_choice':
                $scores = [];
                foreach ($q['options'] ?? [] as $o) {
                    if (is_array($value) && in_array($o['id'], $value, true) && $o['score'] !== null) {
                        $scores[] = $o['score'];
                    }
                }

                return $scores ? round(array_sum($scores) / count($scores), 1) : null;
            case 'number':
                $n = $q['number'] ?? [];
                if (! is_numeric($value) || (($n['pass_min'] ?? null) === null && ($n['pass_max'] ?? null) === null)) {
                    return null;
                }

                return (($n['pass_min'] ?? null) !== null && $value < $n['pass_min']) || (($n['pass_max'] ?? null) !== null && $value > $n['pass_max']) ? 0.0 : 100.0;
            default:
                return null;
        }
    }
}
