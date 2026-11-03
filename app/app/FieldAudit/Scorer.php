<?php

namespace App\FieldAudit;

/**
 * Evaluación de una auditoría. Es una función pura de (estructura, respuestas, evidencias).
 *
 * Siempre entrega: qué preguntas son visibles (árbol de decisiones), avance, faltantes obligatorios y los HALLAZGOS
 * (respuestas que el diseñador marcó como problema). El puntaje es opcional: solo se calcula si la plantilla lo activa.
 *
 * Qué genera hallazgo, por tipo:
 *  - yes_no: las respuestas que el diseñador marca (Sí, No y/o N/A) · scale: nota menor o igual al umbral · choice/multi_choice: opciones marcadas
 *  - number: fuera del rango aceptable
 *
 * Puntaje (opcional): Sí=100/No=0 (N/A se excluye), escala proporcional, opciones con puntaje, número dentro/fuera del rango;
 * sección = promedio ponderado de sus preguntas; total = promedio ponderado de las secciones.
 */
class Scorer
{
    /**
     * @param array<string, array{value?: mixed, comment?: ?string}> $answers  por id de pregunta
     * @param array<string, array{photo?: int, signature?: int}> $evidence      conteo por id de pregunta
     */
    public static function evaluate(array $structure, array $answers, array $evidence, bool $scoring = false, int $passThreshold = 80): array
    {
        $visible = self::visibility($structure, $answers);
        $sections = [];
        $missing = [];
        $findings = [];
        $bySeverity = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        $answered = 0;
        $total = 0;
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($structure['sections'] ?? [] as $s) {
            $sSum = 0.0;
            $sWeight = 0.0;
            $sAnswered = 0;
            $sTotal = 0;
            $sFindings = 0;
            $shown = false;

            foreach ($s['questions'] ?? [] as $q) {
                if (! ($visible[$q['id']] ?? false)) {
                    continue;
                }
                $shown = true;
                $sTotal++;
                $total++;
                $a = $answers[$q['id']] ?? [];
                $value = $a['value'] ?? null;
                $isAnswered = self::answered($q, $value, $evidence[$q['id']] ?? []);
                if ($isAnswered) {
                    $sAnswered++;
                    $answered++;
                }

                $isFinding = $isAnswered && self::isFinding($q, $value);
                if ($isFinding) {
                    $findings[] = $q['id'];
                    $sFindings++;
                    $bySeverity[$q['severity']]++;
                }
                if ($scoring && $isAnswered && ($score = self::questionScore($q, $value)) !== null) {
                    $sSum += $score * $q['weight'];
                    $sWeight += $q['weight'];
                }

                $text = $q['text'];
                if ($q['required'] && ! $isAnswered) {
                    $missing[] = ['question_id' => $q['id'], 'reason' => 'required', 'text' => $text];
                    continue;
                }
                $photos = $evidence[$q['id']]['photo'] ?? 0;
                $rule = $q['evidence']['photo'] ?? 'none';
                if (($rule === 'required' && $isAnswered && $photos < 1) || ($rule === 'on_fail' && $isFinding && $photos < 1)) {
                    $missing[] = ['question_id' => $q['id'], 'reason' => 'photo_required', 'text' => $text];
                }
                if (($q['evidence']['comment_on_fail'] ?? false) && $isFinding && trim((string) ($a['comment'] ?? '')) === '') {
                    $missing[] = ['question_id' => $q['id'], 'reason' => 'comment_required', 'text' => $text];
                }
            }

            if (! $shown) {
                continue;   // sección completa omitida por una condición
            }
            $sScore = $scoring && $sWeight > 0 ? round($sSum / $sWeight, 1) : null;
            if ($sScore !== null) {
                $weightedSum += $sScore * $s['weight'];
                $weightTotal += $s['weight'];
            }
            $sections[] = ['id' => $s['id'], 'title' => $s['title'], 'score' => $sScore, 'answered' => $sAnswered, 'total' => $sTotal, 'findings' => $sFindings];
        }

        $score = $scoring && $weightTotal > 0 ? round($weightedSum / $weightTotal, 1) : null;

        return [
            'sections' => $sections,
            'scoring' => $scoring,
            'score' => $score,
            'result' => $scoring ? (($score === null || $score >= $passThreshold) ? 'pass' : 'fail') : null,
            'findings' => $findings,
            'failed' => $findings,                       // alias (nombre usado por la interfaz)
            'critical_failures' => $bySeverity['critical'],
            'findings_by_severity' => $bySeverity,
            'missing' => $missing,
            'progress' => ['answered' => $answered, 'total' => $total],
            'visible' => $visible,
        ];
    }

    /** @return array<string, array> preguntas con su sección, en orden */
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

    /**
     * Árbol de decisiones: qué preguntas se muestran según las respuestas. Una pregunta o sección sin condición siempre se muestra.
     * Si la pregunta de la que depende está oculta o sin responder, la condición no se cumple (el seguimiento aparece al responder la principal).
     *
     * @return array<string, bool> id de pregunta => visible
     */
    public static function visibility(array $structure, array $answers): array
    {
        $visible = [];
        foreach ($structure['sections'] ?? [] as $s) {
            $sectionOk = self::passes($s['show_if'] ?? null, $answers, $visible);
            foreach ($s['questions'] ?? [] as $q) {
                $visible[$q['id']] = $sectionOk && self::passes($q['show_if'] ?? null, $answers, $visible);
            }
        }

        return $visible;
    }

    private static function passes(?array $cond, array $answers, array $visible): bool
    {
        if (! $cond) {
            return true;
        }
        // formato antiguo {question, equals}
        $conditions = $cond['conditions'] ?? [['question' => $cond['question'] ?? '', 'op' => 'equals', 'value' => $cond['equals'] ?? null]];
        $results = array_map(function ($c) use ($answers, $visible) {
            if (! ($visible[$c['question']] ?? false)) {
                return false;
            }
            $v = $answers[$c['question']]['value'] ?? null;
            if ($v === null || $v === '' || $v === []) {
                return false;
            }
            $have = is_array($v) ? $v : [$v];

            return match ($c['op'] ?? 'equals') {
                'not_equals' => ! in_array($c['value'], $have, true),
                'any_of' => array_intersect((array) $c['value'], $have) !== [],
                default => in_array($c['value'], $have, true),
            };
        }, $conditions);

        return ($cond['mode'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
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

    /** ¿La respuesta es un problema que debe registrarse como hallazgo? */
    public static function isFinding(array $q, mixed $value): bool
    {
        switch ($q['type']) {
            case 'yes_no':
                return in_array($value, $q['finding_on'] ?? [], true);
            case 'scale':
                return ($q['finding_at_or_below'] ?? null) !== null && is_numeric($value) && $value <= $q['finding_at_or_below'];
            case 'choice':
                foreach ($q['options'] ?? [] as $o) {
                    if ($o['id'] === $value) {
                        return (bool) ($o['finding'] ?? false);
                    }
                }

                return false;
            case 'multi_choice':
                foreach ($q['options'] ?? [] as $o) {
                    if (is_array($value) && in_array($o['id'], $value, true) && ($o['finding'] ?? false)) {
                        return true;
                    }
                }

                return false;
            case 'number':
                $n = $q['number'] ?? [];

                return is_numeric($value) && ((($n['pass_min'] ?? null) !== null && $value < $n['pass_min']) || (($n['pass_max'] ?? null) !== null && $value > $n['pass_max']));
            default:
                return false;
        }
    }

    /** Solo se usa si la plantilla activa el puntaje. */
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

                return self::isFinding($q, $value) ? 0.0 : 100.0;
            default:
                return null;
        }
    }
}
