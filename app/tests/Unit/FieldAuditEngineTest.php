<?php

namespace Tests\Unit;

use App\FieldAudit\Scorer;
use App\FieldAudit\TemplateValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldAuditEngineTest extends TestCase
{
    private function ans(array $pairs): array
    {
        return array_map(fn ($v) => ['value' => $v], $pairs);
    }

    private function norm(array $sections): array
    {
        return TemplateValidator::normalize(['sections' => $sections], true);
    }

    /** Estructura "de levantamiento": sin puntaje, con respuestas que generan hallazgo. */
    private function survey(): array
    {
        return $this->norm([
            ['id' => 's1', 'title' => 'Local', 'questions' => [
                ['id' => 'q1', 'text' => '¿Extintor vigente?', 'type' => 'yes_no', 'finding_on' => ['no'], 'severity' => 'high', 'evidence' => ['photo' => 'on_fail', 'comment_on_fail' => true]],
                ['id' => 'q2', 'text' => 'Orden y limpieza (1-5)', 'type' => 'scale', 'finding_at_or_below' => 2],
                ['id' => 'q3', 'text' => 'Saludo', 'type' => 'choice', 'options' => [['id' => 'a', 'label' => 'Siempre', 'score' => 100], ['id' => 'b', 'label' => 'Nunca', 'score' => 0, 'finding' => true]]],
                ['id' => 'q4', 'text' => 'Temperatura', 'type' => 'number', 'number' => ['pass_min' => 18, 'pass_max' => 24]],
                ['id' => 'q5', 'text' => 'Observaciones', 'type' => 'text', 'required' => false],
            ]],
        ]);
    }

    public function test_findings_come_from_the_answers_marked_as_problems_and_scoring_is_off_by_default(): void
    {
        $r = Scorer::evaluate($this->survey(), $this->ans(['q1' => 'yes', 'q2' => 5, 'q3' => 'a', 'q4' => 21]), []);
        $this->assertSame([], $r['findings']);
        $this->assertNull($r['score']);
        $this->assertNull($r['result']);                                                  // sin puntaje no hay "cumple / no cumple"
        $this->assertSame([4, 5], [$r['progress']['answered'], $r['progress']['total']]);

        $r = Scorer::evaluate($this->survey(), $this->ans(['q1' => 'no', 'q2' => 2, 'q3' => 'b', 'q4' => 30]), []);
        $this->assertEqualsCanonicalizing(['q1', 'q2', 'q3', 'q4'], $r['findings']);
        $this->assertSame(['low' => 0, 'medium' => 3, 'high' => 1, 'critical' => 0], $r['findings_by_severity']);

        $ok = Scorer::evaluate($this->survey(), $this->ans(['q1' => 'na', 'q2' => 3, 'q3' => 'a', 'q4' => 18]), []);
        $this->assertSame([], $ok['findings']);                                            // N/A, nota 3, valor en el límite: no son problema
    }

    public function test_optional_scoring_weights_sections_and_threshold(): void
    {
        $s = $this->norm([
            ['id' => 's1', 'title' => 'A', 'weight' => 1, 'questions' => [['id' => 'q1', 'text' => 'x', 'type' => 'yes_no', 'weight' => 2], ['id' => 'q2', 'text' => 'y', 'type' => 'scale']]],
            ['id' => 's2', 'title' => 'B', 'weight' => 3, 'questions' => [['id' => 'q3', 'text' => 'z', 'type' => 'choice', 'options' => [['id' => 'a', 'label' => 'A', 'score' => 50], ['id' => 'b', 'label' => 'B', 'score' => 0]]]]],
        ]);
        // s1: (0*2 + 50*1)/3 = 16.7 ; s2: 50 ; total: (16.7*1 + 50*3)/4 = 41.7
        $r = Scorer::evaluate($s, $this->ans(['q1' => 'no', 'q2' => 3, 'q3' => 'a']), [], true, 80);
        $this->assertSame([16.7, 50.0, 41.7, 'fail'], [$r['sections'][0]['score'], $r['sections'][1]['score'], $r['score'], $r['result']]);
        $this->assertSame('pass', Scorer::evaluate($s, $this->ans(['q1' => 'no', 'q2' => 3, 'q3' => 'a']), [], true, 40)['result']);
        $this->assertNull(Scorer::evaluate($s, $this->ans(['q1' => 'no', 'q2' => 3, 'q3' => 'a']), [])['score']);          // apagado: no calcula
    }

    public function test_decision_tree_hides_follow_up_questions_and_whole_sections(): void
    {
        $s = $this->norm([
            ['id' => 's1', 'title' => 'Exhibición', 'questions' => [
                ['id' => 'q1', 'text' => '¿Tiene exhibición?', 'type' => 'yes_no'],                       // decisión: ninguna respuesta es hallazgo
                ['id' => 'q2', 'text' => 'Estado de la exhibición', 'type' => 'choice', 'options' => [['id' => 'bueno', 'label' => 'Bueno'], ['id' => 'malo', 'label' => 'Malo', 'finding' => true]],
                    'show_if' => ['mode' => 'all', 'conditions' => [['question' => 'q1', 'op' => 'equals', 'value' => 'yes']]]],
                ['id' => 'q3', 'text' => 'Describa el daño', 'type' => 'text', 'show_if' => ['conditions' => [['question' => 'q2', 'op' => 'equals', 'value' => 'malo']]]],
            ]],
            ['id' => 's2', 'title' => 'Sin exhibición', 'show_if' => ['conditions' => [['question' => 'q1', 'op' => 'equals', 'value' => 'no']]], 'questions' => [
                ['id' => 'q4', 'text' => 'Motivo de no tener exhibición', 'type' => 'text'],
            ]],
        ]);

        $vis = fn (array $a) => array_keys(array_filter(Scorer::evaluate($s, $this->ans($a), [])['visible']));
        $this->assertSame(['q1'], $vis([]));                                                      // sin responder, nada depende
        $this->assertSame(['q1', 'q2'], $vis(['q1' => 'yes']));
        $this->assertSame(['q1', 'q2', 'q3'], $vis(['q1' => 'yes', 'q2' => 'malo']));
        $this->assertSame(['q1', 'q4'], $vis(['q1' => 'no']));                                    // «No»: se salta Estado y aparece la otra sección
        $this->assertSame(['q1'], $vis(['q1' => 'na']));

        // cascada: si q1 pasa a «No», q3 queda oculta aunque conserve su respuesta anterior
        $r = Scorer::evaluate($s, $this->ans(['q1' => 'no', 'q2' => 'malo', 'q3' => 'Roto', 'q4' => 'No hay espacio']), []);
        $this->assertSame([2, 2], [$r['progress']['answered'], $r['progress']['total']]);
        $this->assertSame([], $r['findings']);                                                    // el hallazgo de q2 se descarta porque q2 ya no aplica
        $this->assertSame(['Sin exhibición'], array_column(array_slice($r['sections'], 1), 'title'));
        $this->assertCount(2, $r['sections']);

        // lo oculto no es "faltante"
        $m = Scorer::evaluate($s, $this->ans(['q1' => 'yes']), [])['missing'];
        $this->assertSame(['q2'], array_column($m, 'question_id'));
    }

    public function test_condition_operators_modes_and_multiple_choice(): void
    {
        $s = $this->norm([['id' => 's', 'title' => 'T', 'questions' => [
            ['id' => 'a', 'text' => 'Sí/No', 'type' => 'yes_no'],
            ['id' => 'm', 'text' => 'Tipos', 'type' => 'multi_choice', 'options' => [['id' => 'x', 'label' => 'X'], ['id' => 'y', 'label' => 'Y']]],
            ['id' => 'n', 'text' => 'Escala', 'type' => 'scale'],
            ['id' => 'p1', 'text' => 'no es No', 'type' => 'text', 'show_if' => ['conditions' => [['question' => 'a', 'op' => 'not_equals', 'value' => 'no']]]],
            ['id' => 'p2', 'text' => 'alguna de', 'type' => 'text', 'show_if' => ['conditions' => [['question' => 'm', 'op' => 'any_of', 'value' => ['y']]]]],
            ['id' => 'p3', 'text' => 'ambas (todas)', 'type' => 'text', 'show_if' => ['mode' => 'all', 'conditions' => [['question' => 'a', 'op' => 'equals', 'value' => 'yes'], ['question' => 'n', 'op' => 'equals', 'value' => 5]]]],
            ['id' => 'p4', 'text' => 'cualquiera', 'type' => 'text', 'show_if' => ['mode' => 'any', 'conditions' => [['question' => 'a', 'op' => 'equals', 'value' => 'no'], ['question' => 'n', 'op' => 'equals', 'value' => 1]]]],
        ]]]);
        $vis = fn (array $a) => array_values(array_diff(array_keys(array_filter(Scorer::evaluate($s, $this->ans($a), [])['visible'])), ['a', 'm', 'n']));

        $this->assertSame([], $vis([]));
        $this->assertSame(['p1'], $vis(['a' => 'yes']));
        $this->assertSame(['p4'], $vis(['a' => 'no']));
        $this->assertSame(['p1', 'p3'], $vis(['a' => 'yes', 'n' => 5]));
        $this->assertSame(['p1', 'p2'], $vis(['a' => 'na', 'm' => ['x', 'y']]));                    // selección múltiple: «alguna de»
        $this->assertSame(['p1', 'p4'], $vis(['a' => 'yes', 'n' => 1]));                            // «cualquiera»: basta una
    }

    public function test_missing_requirements_evidence_and_comments_apply_to_findings(): void
    {
        $r = Scorer::evaluate($this->survey(), $this->ans(['q1' => 'no', 'q2' => 4]), ['q1' => ['photo' => 0]]);
        $byQ = collect($r['missing'])->groupBy('question_id')->map(fn ($g) => $g->pluck('reason')->all())->all();
        $this->assertEqualsCanonicalizing(['photo_required', 'comment_required'], $byQ['q1']);   // hay hallazgo: exige foto y comentario
        $this->assertSame(['required'], $byQ['q3']);
        $this->assertSame(['required'], $byQ['q4']);
        $this->assertArrayNotHasKey('q5', $byQ);

        $ok = Scorer::evaluate($this->survey(), ['q1' => ['value' => 'no', 'comment' => 'Roto'], 'q2' => ['value' => 4], 'q3' => ['value' => 'a'], 'q4' => ['value' => 20]], ['q1' => ['photo' => 1]]);
        $this->assertSame([], $ok['missing']);
        $fine = Scorer::evaluate($this->survey(), $this->ans(['q1' => 'yes', 'q2' => 4, 'q3' => 'a', 'q4' => 20]), []);
        $this->assertSame([], $fine['missing']);                                                  // sin hallazgo no se pide foto ni comentario
    }

    public function test_photo_signature_and_gps_questions_are_answered_by_evidence_or_location(): void
    {
        $s = $this->norm([['title' => 'E', 'questions' => [
            ['id' => 'p', 'text' => 'Foto de la fachada', 'type' => 'photo'], ['id' => 'f', 'text' => 'Firma del encargado', 'type' => 'signature'], ['id' => 'g', 'text' => 'Ubicación', 'type' => 'gps'],
        ]]]);
        $this->assertCount(3, Scorer::evaluate($s, [], [])['missing']);
        $r = Scorer::evaluate($s, ['g' => ['value' => ['lat' => -33.4, 'lng' => -70.6]]], ['p' => ['photo' => 2], 'f' => ['signature' => 1]]);
        $this->assertSame([], $r['missing']);
        $this->assertSame([3, 3], [$r['progress']['answered'], $r['progress']['total']]);
    }

    public function test_validator_normalizes_legacy_formats_and_rejects_inconsistent_structures(): void
    {
        $n = $this->norm([['title' => 'S', 'questions' => [
            ['id' => 'a', 'text' => 'Q', 'type' => 'yes_no', 'critical' => true],
            ['id' => 'b', 'text' => 'R', 'type' => 'text', 'show_if' => ['question' => 'a', 'equals' => 'no']],     // formato antiguo
        ]]]);
        [$a, $b] = $n['sections'][0]['questions'];
        $this->assertSame('critical', $a['severity']);                                                         // "crítica" ahora es solo la severidad del hallazgo
        $this->assertArrayNotHasKey('critical', $a);
        $this->assertSame([], $a['finding_on']);                                                              // sin marcar, ninguna respuesta es hallazgo
        $this->assertSame(['mode' => 'all', 'conditions' => [['question' => 'a', 'op' => 'equals', 'value' => 'no']]], $b['show_if']);

        $bad = [
            [],
            [['title' => '', 'questions' => [['text' => 'x', 'type' => 'yes_no']]]],
            [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'volar']]]],
            [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'choice', 'options' => [['label' => 'solo una']]]]]],
            [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'scale', 'scale' => ['min' => 5, 'max' => 1]]]]],
            [['title' => 'S', 'questions' => [['text' => 'x', 'type' => 'yes_no', 'show_if' => ['conditions' => [['question' => 'inexistente', 'value' => 'yes']]]]]]],
            [['title' => 'S', 'questions' => [['id' => 'a', 'text' => 'x', 'type' => 'yes_no', 'show_if' => ['conditions' => [['question' => 'a', 'value' => 'yes']]]]]]],            // depende de sí misma
            [['title' => 'S', 'questions' => [['id' => 'a', 'text' => 'x', 'type' => 'yes_no'], ['id' => 'b', 'text' => 'y', 'type' => 'text', 'show_if' => ['conditions' => [['question' => 'a', 'value' => 'quizás']]]]]]],   // respuesta imposible
            [['title' => 'S', 'questions' => [['id' => 'a', 'text' => 'x', 'type' => 'text'], ['id' => 'b', 'text' => 'y', 'type' => 'text', 'show_if' => ['conditions' => [['question' => 'a', 'value' => 'z']]]]]]],             // texto no controla
            [['title' => 'S', 'questions' => [['id' => 'b', 'text' => 'y', 'type' => 'text', 'show_if' => ['conditions' => [['question' => 'c', 'value' => 'yes']]]], ['id' => 'c', 'text' => 'x', 'type' => 'yes_no']]]],       // depende de una posterior
            [['title' => 'S', 'questions' => []]],
        ];
        foreach ($bad as $i => $sections) {
            try {
                TemplateValidator::normalize(['sections' => $sections], true);
                $this->fail("la estructura #{$i} debía rechazarse");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        // una sección puede depender de una pregunta de una sección anterior; un borrador incompleto se acepta
        $ok = TemplateValidator::normalize(['sections' => [
            ['id' => 's1', 'title' => 'A', 'questions' => [['id' => 'q1', 'text' => 'x', 'type' => 'yes_no']]],
            ['id' => 's2', 'title' => 'B', 'show_if' => ['conditions' => [['question' => 'q1', 'op' => 'equals', 'value' => 'yes']]], 'questions' => [['text' => 'y', 'type' => 'text']]],
        ]], true);
        $this->assertSame('q1', $ok['sections'][1]['show_if']['conditions'][0]['question']);
        $this->assertSame([], TemplateValidator::normalize(['sections' => []], false)['sections']);
        $d = TemplateValidator::normalize(['sections' => [['id' => 'x', 'title' => 'A', 'questions' => [['id' => 'dup', 'text' => 'a', 'type' => 'text'], ['id' => 'dup', 'text' => 'b', 'type' => 'text']]]]], true);
        $this->assertNotSame($d['sections'][0]['questions'][0]['id'], $d['sections'][0]['questions'][1]['id']);
    }
}
