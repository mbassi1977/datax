<?php

namespace Tests\Unit;

use App\FieldAudit\Scorer;
use App\FieldAudit\TemplateValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldAuditEngineTest extends TestCase
{
    private function structure(): array
    {
        return TemplateValidator::normalize(['sections' => [
            ['id' => 's1', 'title' => 'Fachada', 'weight' => 1, 'questions' => [
                ['id' => 'q1', 'text' => '¿Letrero en buen estado?', 'type' => 'yes_no', 'weight' => 2, 'evidence' => ['photo' => 'on_fail', 'comment_on_fail' => true]],
                ['id' => 'q2', 'text' => 'Limpieza (1-5)', 'type' => 'scale'],
                ['id' => 'q3', 'text' => 'Extintor vigente', 'type' => 'yes_no', 'critical' => true],
            ]],
            ['id' => 's2', 'title' => 'Atención', 'weight' => 3, 'questions' => [
                ['id' => 'q4', 'text' => 'Saludo', 'type' => 'choice', 'options' => [['id' => 'a', 'label' => 'Siempre', 'score' => 100], ['id' => 'b', 'label' => 'A veces', 'score' => 50], ['id' => 'c', 'label' => 'Nunca', 'score' => 0]]],
                ['id' => 'q5', 'text' => 'Temperatura', 'type' => 'number', 'number' => ['pass_min' => 18, 'pass_max' => 24]],
                ['id' => 'q6', 'text' => 'Observaciones', 'type' => 'text', 'required' => false],
                ['id' => 'q7', 'text' => 'Detalle de la falla', 'type' => 'text', 'required' => true, 'show_if' => ['question' => 'q3', 'equals' => 'no']],
            ]],
        ]], true);
    }

    private function ans(array $pairs): array
    {
        return array_map(fn ($v) => ['value' => $v], $pairs);
    }

    public function test_scores_sections_weighted_total_and_pass_result(): void
    {
        $r = Scorer::evaluate($this->structure(), $this->ans(['q1' => 'yes', 'q2' => 5, 'q3' => 'yes', 'q4' => 'a', 'q5' => 21, 'q6' => 'ok']), [], 80);

        $this->assertSame([100.0, 100.0], [$r['sections'][0]['score'], $r['sections'][1]['score']]);
        $this->assertSame(100.0, $r['score']);
        $this->assertSame('pass', $r['result']);
        $this->assertSame([], $r['missing']);
        $this->assertSame([6, 6], [$r['progress']['answered'], $r['progress']['total']]);   // q7 oculta (q3 = sí)
    }

    public function test_partial_scores_and_the_section_weights(): void
    {
        // s1: q1 No (0, peso 2), q2 = 3/5 -> 50, q3 Sí (100)  => (0*2 + 50*1 + 100*1)/4 = 37.5
        // s2: q4 "A veces" 50, q5 fuera de rango 0 => 25
        $r = Scorer::evaluate($this->structure(), $this->ans(['q1' => 'no', 'q2' => 3, 'q3' => 'yes', 'q4' => 'b', 'q5' => 30]), [], 80);
        $this->assertSame([37.5, 25.0], [$r['sections'][0]['score'], $r['sections'][1]['score']]);
        $this->assertSame(28.1, $r['score']);                                   // (37.5*1 + 25*3)/4 = 28.125
        $this->assertSame('fail', $r['result']);
        $this->assertEqualsCanonicalizing(['q1', 'q5'], $r['failed']);
    }

    public function test_critical_failure_fails_the_audit_even_with_a_high_score(): void
    {
        $r = Scorer::evaluate($this->structure(), $this->ans(['q1' => 'yes', 'q2' => 5, 'q3' => 'no', 'q4' => 'a', 'q5' => 20, 'q7' => 'Sin extintor']), [], 10);
        $this->assertSame(1, $r['critical_failures']);
        $this->assertSame('fail', $r['result']);
        $this->assertGreaterThan(10, $r['score']);
    }

    public function test_missing_requirements_conditions_evidence_and_comments(): void
    {
        $r = Scorer::evaluate($this->structure(), $this->ans(['q1' => 'no', 'q2' => 4, 'q3' => 'no']), ['q1' => ['photo' => 0]], 80);
        $byQ = collect($r['missing'])->groupBy('question_id')->map(fn ($g) => $g->pluck('reason')->all())->all();

        $this->assertEqualsCanonicalizing(['photo_required', 'comment_required'], $byQ['q1']);   // falló: exige foto y comentario
        $this->assertSame(['required'], $byQ['q4']);
        $this->assertSame(['required'], $byQ['q5']);
        $this->assertSame(['required'], $byQ['q7']);                                              // ahora visible (q3 = no) y vacía
        $this->assertArrayNotHasKey('q6', $byQ);                                                   // opcional

        $ok = Scorer::evaluate($this->structure(), ['q1' => ['value' => 'no', 'comment' => 'Roto'], 'q2' => ['value' => 4], 'q3' => ['value' => 'yes'], 'q4' => ['value' => 'a'], 'q5' => ['value' => 20]], ['q1' => ['photo' => 1]], 80);
        $this->assertSame([], $ok['missing']);
    }

    public function test_not_applicable_is_excluded_and_unanswered_optional_does_not_count(): void
    {
        $s = TemplateValidator::normalize(['sections' => [['title' => 'A', 'questions' => [
            ['id' => 'a', 'text' => 'x', 'type' => 'yes_no'], ['id' => 'b', 'text' => 'y', 'type' => 'yes_no'], ['id' => 'c', 'text' => 'z', 'type' => 'scale', 'required' => false],
        ]]]], true);
        $r = Scorer::evaluate($s, $this->ans(['a' => 'yes', 'b' => 'na']), [], 80);
        $this->assertSame(100.0, $r['score']);
        $this->assertSame([], $r['missing']);

        $none = Scorer::evaluate($s, [], [], 80);
        $this->assertNull($none['score']);
    }

    public function test_photo_signature_and_gps_questions_are_answered_by_evidence_or_location(): void
    {
        $s = TemplateValidator::normalize(['sections' => [['title' => 'E', 'questions' => [
            ['id' => 'p', 'text' => 'Foto de la fachada', 'type' => 'photo'], ['id' => 'f', 'text' => 'Firma del encargado', 'type' => 'signature'], ['id' => 'g', 'text' => 'Ubicación', 'type' => 'gps'],
        ]]]], true);
        $this->assertCount(3, Scorer::evaluate($s, [], [], 80)['missing']);
        $r = Scorer::evaluate($s, ['g' => ['value' => ['lat' => -33.4, 'lng' => -70.6]]], ['p' => ['photo' => 2], 'f' => ['signature' => 1]], 80);
        $this->assertSame([], $r['missing']);
        $this->assertSame([3, 3], [$r['progress']['answered'], $r['progress']['total']]);
    }

    public function test_validator_assigns_ids_defaults_and_rejects_inconsistent_structures(): void
    {
        $n = TemplateValidator::normalize(['sections' => [['title' => 'S', 'questions' => [['text' => 'Q', 'type' => 'yes_no', 'critical' => true]]]]], true);
        $q = $n['sections'][0]['questions'][0];
        $this->assertMatchesRegularExpression('/^q_[a-z0-9]{8}$/', $q['id']);
        $this->assertSame(['critical', true, 1.0], [$q['severity'], $q['critical'], $q['weight']]);          // crítica => severidad crítica

        $bad = [
            ['sections' => []],                                                                                                    // publicar sin secciones
            ['sections' => [['title' => '', 'questions' => [['text' => 'x', 'type' => 'yes_no']]]]],
            ['sections' => [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'volar']]]]],
            ['sections' => [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'choice', 'options' => [['label' => 'solo una']]]]]]],
            ['sections' => [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'scale', 'scale' => ['min' => 5, 'max' => 1]]]]]],
            ['sections' => [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'yes_no', 'show_if' => ['question' => 'inexistente', 'equals' => 'yes']]]]]],
            ['sections' => [['title' => 'S', 'questions' => []]]],
        ];
        foreach ($bad as $i => $structure) {
            try {
                TemplateValidator::normalize($structure, true);
                $this->fail("la estructura #{$i} debía rechazarse");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        // un borrador incompleto sí se acepta; los ids repetidos se corrigen
        $this->assertSame([], TemplateValidator::normalize(['sections' => []], false)['sections']);
        $d = TemplateValidator::normalize(['sections' => [['id' => 'x', 'title' => 'A', 'questions' => [['id' => 'dup', 'text' => 'a', 'type' => 'text'], ['id' => 'dup', 'text' => 'b', 'type' => 'text']]]]], true);
        $this->assertNotSame($d['sections'][0]['questions'][0]['id'], $d['sections'][0]['questions'][1]['id']);
    }
}
