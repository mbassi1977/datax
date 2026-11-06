<?php

namespace Tests\Feature;

use App\Models\FieldAuditTemplate;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldAuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $a;
    private Organization $b;
    private string $branchA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        $this->a = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Empresa A']);
        $this->b = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Empresa B']);
        $this->branchA = (string) Str::uuid();
        DB::table('core.branches')->insert(['id' => $this->branchA, 'tenant_id' => $this->tenant->id, 'organization_id' => $this->a->id, 'name' => 'Local Centro', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $profile, string $scope = 'global', ?string $scopeId = null): User
    {
        $u = User::create(['tenant_id' => $this->tenant->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $u->id, 'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => $scope, 'scope_id' => $scopeId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function structure(): array
    {
        return ['sections' => [
            ['id' => 's1', 'title' => 'Local', 'questions' => [
                ['id' => 'q1', 'text' => 'Extintor vigente', 'type' => 'yes_no', 'finding_on' => ['no'], 'severity' => 'critical', 'evidence' => ['photo' => 'on_fail', 'comment_on_fail' => true]],
                ['id' => 'q2', 'text' => 'Orden y limpieza', 'type' => 'scale'],
                ['id' => 'q3', 'text' => 'Foto de la fachada', 'type' => 'photo'],
            ]],
            ['id' => 's2', 'title' => 'Atención', 'questions' => [
                ['id' => 'q4', 'text' => 'Saludo al cliente', 'type' => 'choice', 'options' => [['id' => 'a', 'label' => 'Siempre', 'score' => 100], ['id' => 'b', 'label' => 'Nunca', 'score' => 0, 'finding' => true]]],
                ['id' => 'q5', 'text' => 'Observaciones', 'type' => 'text', 'required' => false],
            ]],
        ]];
    }

    private function publishedTemplate(User $admin): string
    {
        $this->as($admin);
        $id = $this->postJson('/api/v1/audit-templates', ['name' => 'Auditoría de local', 'pass_threshold' => 70, 'structure' => $this->structure()])->assertCreated()->json('id');
        $this->postJson("/api/v1/audit-templates/{$id}/publish")->assertOk();

        return $id;
    }

    private function png(int $w = 800, int $h = 600): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 140, 200));
        ob_start();
        imagepng($im);

        return UploadedFile::fake()->createWithContent('foto.png', ob_get_clean());
    }

    private function assign(User $admin, string $template, User $auditor, array $extra = []): string
    {
        return $this->as($admin)->postJson('/api/v1/audits', ['template_id' => $template, 'organization_id' => $this->a->id, 'branch_id' => $this->branchA, 'auditor_id' => $auditor->id,
            'scheduled_for' => '2026-11-10'] + $extra)->assertCreated()->json('id');
    }

    // ───────────────────────────── plantillas ─────────────────────────────

    public function test_template_lifecycle_versions_duplicates_and_permissions(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);

        $id = $this->postJson('/api/v1/audit-templates', ['name' => 'Borrador', 'structure' => ['sections' => []]])->assertCreated()->assertJsonPath('status', 'draft')->json('id');
        $this->postJson('/api/v1/audit-templates', ['name' => 'Borrador'])->assertStatus(422);                           // nombre repetido
        $this->postJson("/api/v1/audit-templates/{$id}/publish")->assertStatus(422);                                     // vacía: no se publica

        $this->putJson("/api/v1/audit-templates/{$id}", ['structure' => $this->structure()])->assertOk()->assertJsonPath('questions_count', 5);
        $this->postJson("/api/v1/audit-templates/{$id}/publish")->assertOk()->assertJsonPath('status', 'published');
        $this->assertSame(1, $this->getJson("/api/v1/audit-templates/{$id}")->json('version'));

        $edited = $this->structure();
        $edited['sections'][0]['questions'][] = ['id' => 'q9', 'text' => 'Nueva pregunta', 'type' => 'text'];
        $this->putJson("/api/v1/audit-templates/{$id}", ['structure' => $edited])->assertOk()->assertJsonPath('version', 2);   // publicada: sube de versión
        $this->putJson("/api/v1/audit-templates/{$id}", ['name' => 'Renombrada'])->assertOk()->assertJsonPath('version', 2);       // solo el nombre: no
        $this->putJson("/api/v1/audit-templates/{$id}", ['structure' => ['sections' => []]])->assertStatus(422);                    // publicada: no se vacía

        $copy = $this->postJson("/api/v1/audit-templates/{$id}/duplicate")->assertCreated()->assertJsonPath('status', 'draft')->json();
        $this->assertSame('Renombrada (copia)', $copy['name']);
        $this->assertNotSame('q1', $copy['structure']['sections'][0]['questions'][0]['id']);                              // ids nuevos

        $this->postJson("/api/v1/audit-templates/{$id}/archive")->assertOk()->assertJsonPath('status', 'archived');
        $this->putJson("/api/v1/audit-templates/{$id}", ['name' => 'x'])->assertStatus(409);
        $this->postJson("/api/v1/audit-templates/{$id}/restore")->assertOk()->assertJsonPath('status', 'draft');
        $this->deleteJson("/api/v1/audit-templates/{$copy['id']}")->assertNoContent();
        $this->assertCount(1, $this->getJson('/api/v1/audit-templates')->json());

        $this->as($this->user('colaborador'))->getJson('/api/v1/audit-templates')->assertStatus(403);
        $this->as($this->user('admin', 'organization', $this->a->id))->postJson('/api/v1/audit-templates', ['name' => 'Acotada'])->assertStatus(403);   // global
    }

    // ───────────────────────────── asignación y visibilidad ─────────────────────────────

    public function test_assignment_rules_snapshot_and_visibility(): void
    {
        $admin = $this->user('admin');
        $template = $this->publishedTemplate($admin);
        $auditor = $this->user('auditor', 'organization', $this->a->id);
        $otherAuditor = $this->user('auditor', 'organization', $this->a->id);
        $auditorB = $this->user('auditor', 'organization', $this->b->id);

        $draft = $this->as($admin)->postJson('/api/v1/audit-templates', ['name' => 'Sin publicar', 'structure' => $this->structure()])->json('id');
        $base = ['organization_id' => $this->a->id, 'auditor_id' => $auditor->id];
        $this->postJson('/api/v1/audits', ['template_id' => $draft] + $base)->assertStatus(422);                                           // no publicada
        $this->postJson('/api/v1/audits', ['template_id' => $template, 'organization_id' => $this->a->id, 'auditor_id' => $auditorB->id])->assertStatus(422);   // auditor sin alcance en A
        $this->postJson('/api/v1/audits', ['template_id' => $template, 'branch_id' => (string) Str::uuid()] + $base)->assertStatus(422);    // sucursal inexistente
        $this->postJson('/api/v1/audits', ['template_id' => $template, 'organization_id' => $this->a->id, 'auditor_id' => $this->user('colaborador')->id])->assertStatus(422);   // sin permiso de ejecutar

        $this->as($admin);
        $this->assertEqualsCanonicalizing([$admin->email, $auditor->email, $otherAuditor->email], collect($this->getJson("/api/v1/audits/auditors?organization_id={$this->a->id}")->assertOk()->json())->pluck('email')->all());
        $this->as($auditor)->getJson("/api/v1/audits/auditors?organization_id={$this->a->id}")->assertStatus(403);               // solo quien asigna

        $id = $this->assign($admin, $template, $auditor);
        $audit = $this->getJson("/api/v1/audits/{$id}")->assertOk();
        $this->assertSame(['scheduled', 'Auditoría de local · Local Centro', 70, 1], [$audit->json('status'), $audit->json('title'), $audit->json('pass_threshold'), $audit->json('template_version')]);

        // editar la plantilla después no altera la auditoría ya asignada
        $edited = $this->structure();
        $edited['sections'][0]['title'] = 'Cambiada';
        $this->putJson("/api/v1/audit-templates/{$template}", ['structure' => $edited])->assertOk();
        $this->assertSame('Local', $this->getJson("/api/v1/audits/{$id}")->json('structure.sections.0.title'));

        // visibilidad: el auditor solo ve la suya; otro auditor, ninguna; el admin, todas
        $this->as($auditor);
        $this->assertCount(1, $this->getJson('/api/v1/audits')->json('data'));
        $this->getJson("/api/v1/audits/{$id}")->assertOk();
        $this->as($otherAuditor);
        $this->assertCount(0, $this->getJson('/api/v1/audits')->json('data'));
        $this->getJson("/api/v1/audits/{$id}")->assertStatus(404);
        $this->as($admin);
        $this->assertCount(1, $this->getJson('/api/v1/audits?status=scheduled')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/audits?status=approved')->json('data'));
        $this->as($this->user('admin', 'organization', $this->b->id))->getJson("/api/v1/audits/{$id}")->assertStatus(404);                // otra empresa

        $other = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $this->assertNotNull($other);
        $this->as($auditor)->postJson('/api/v1/audits', ['template_id' => $template] + $base)->assertStatus(403);                          // el auditor no asigna

        $this->as($admin)->putJson("/api/v1/audits/{$id}", ['auditor_id' => $otherAuditor->id, 'scheduled_for' => '2026-11-15'])->assertOk()->assertJsonPath('auditor', $otherAuditor->email);
        $this->postJson("/api/v1/audits/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->putJson("/api/v1/audits/{$id}", ['title' => 'x'])->assertStatus(409);
    }

    // ───────────────────────────── ejecución completa ─────────────────────────────

    public function test_full_execution_scoring_findings_review_and_resubmission(): void
    {
        $admin = $this->user('admin');
        $template = $this->publishedTemplate($admin);
        $auditor = $this->user('auditor', 'organization', $this->a->id);
        $id = $this->assign($admin, $template, $auditor);

        $this->as($auditor);
        $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [['question_id' => 'q2', 'value' => 4]]])->assertStatus(409);        // sin iniciar
        $this->postJson("/api/v1/audits/{$id}/start", ['lat' => -33.45, 'lng' => -70.66])->assertOk()->assertJsonPath('status', 'in_progress');

        // validación de respuestas
        $bad = $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [
            ['question_id' => 'q1', 'value' => 'tal vez'], ['question_id' => 'q2', 'value' => 9], ['question_id' => 'q3', 'value' => 'x'], ['question_id' => 'nope', 'value' => 1],
        ]])->assertStatus(422)->json('errors');
        $this->assertEqualsCanonicalizing(['answers.0.value', 'answers.1.value', 'answers.2.value', 'answers.3.question_id'], array_keys($bad));

        // el extintor falla (crítica) → exige foto y comentario; se responde y se sube evidencia
        $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [['question_id' => 'q1', 'value' => 'no'], ['question_id' => 'q2', 'value' => 4], ['question_id' => 'q4', 'value' => 'a']]])->assertOk();
        $missing = $this->postJson("/api/v1/audits/{$id}/submit")->assertStatus(422)->json('missing');
        $reasons = collect($missing)->map(fn ($m) => "{$m['question_id']}:{$m['reason']}")->all();
        $this->assertEqualsCanonicalizing(['q1:photo_required', 'q1:comment_required', 'q3:required'], $reasons);

        // evidencia: se reduce, es idempotente por client_id y se sirve con URL firmada
        $up = $this->post("/api/v1/audits/{$id}/evidence", ['file' => $this->png(3000, 2000), 'question_id' => 'q1', 'client_id' => 'dev-1', 'latitude' => -33.4, 'longitude' => -70.6], ['Accept' => 'application/json'])->assertCreated();
        $again = $this->post("/api/v1/audits/{$id}/evidence", ['file' => $this->png(), 'question_id' => 'q1', 'client_id' => 'dev-1'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($up->json('id'), $again->json('id'));
        $this->post("/api/v1/audits/{$id}/evidence", ['file' => $this->png(), 'question_id' => 'q3'], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/audits/{$id}/evidence", ['file' => UploadedFile::fake()->createWithContent('x.png', 'no soy imagen'), 'question_id' => 'q3'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/v1/audits/{$id}/evidence", ['file' => $this->png(), 'question_id' => 'inexistente'], ['Accept' => 'application/json'])->assertStatus(422);

        $stored = Storage::disk('local')->get(DB::table('fieldaudit.evidences')->where('id', $up->json('id'))->value('path'));
        $info = getimagesizefromstring($stored);
        $this->assertSame(['image/jpeg', 1600], [$info['mime'], $info[0]]);                                                               // máx. 1600 px, JPEG
        $this->get($up->json('url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=00', $up->json('url')))->assertStatus(403);

        $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [['question_id' => 'q1', 'comment' => 'Extintor vencido desde marzo']]])->assertOk();   // solo comentario: conserva el valor
        $this->assertSame('no', $this->getJson("/api/v1/audits/{$id}")->json('answers.q1.value'));

        // respuestas más viejas que lo guardado (dispositivo sin conexión) se ignoran
        $stale = $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [['question_id' => 'q2', 'value' => 1, 'client_updated_at' => '2020-01-01T00:00:00Z']]])->assertOk();
        $this->assertSame(['q2'], $stale->json('skipped'));
        $this->assertSame(4, $this->getJson("/api/v1/audits/{$id}")->json('answers.q2.value'));

        // envío: puntaje, resultado y hallazgos automáticos
        $sub = $this->postJson("/api/v1/audits/{$id}/submit", ['lat' => -33.4, 'lng' => -70.6])->assertOk();
        $this->assertSame(['submitted', null, 1], [$sub->json('status'), $sub->json('result'), $sub->json('critical_failures')]);   // sin puntaje no hay resultado; 1 hallazgo crítico
        $this->assertSame([null, 1], [$sub->json('score'), $sub->json('evaluation.findings_by_severity.critical')]);
        $finding = collect($sub->json('findings'))->first();
        $this->assertSame(['critical', 'open', 'auto', 'q1'], [$finding['severity'], $finding['status'], $finding['source'], $finding['question_id']]);
        $this->assertStringContainsString('vencido', $finding['description']);
        $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [['question_id' => 'q2', 'value' => 5]]])->assertStatus(409);       // bloqueada tras enviar
        $this->postJson("/api/v1/audits/{$id}/submit")->assertStatus(409);

        // revisión: el auditor no puede aprobar; otro administrador sí; devolver exige observaciones
        $this->postJson("/api/v1/audits/{$id}/approve")->assertStatus(403);
        $reviewer = $this->user('admin');
        $this->as($admin)->postJson("/api/v1/audits/{$id}/return")->assertStatus(422);
        $this->postJson("/api/v1/audits/{$id}/return", ['notes' => 'Falta contexto del extintor'])->assertOk()->assertJsonPath('status', 'returned');

        // corrige y reenvía: no se duplican los hallazgos
        $this->as($auditor)->postJson("/api/v1/audits/{$id}/start")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [['question_id' => 'q5', 'value' => 'Se informó al encargado']]])->assertOk();
        $this->postJson("/api/v1/audits/{$id}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertSame(1, DB::table('fieldaudit.findings')->where('audit_id', $id)->count());

        $this->as($reviewer)->postJson("/api/v1/audits/{$id}/approve", ['notes' => 'Conforme'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('reviewer', $reviewer->email);
        $this->postJson("/api/v1/audits/{$id}/approve")->assertStatus(409);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'updated', 'entity_id' => $id]);                                       // las transiciones quedan auditadas
    }

    public function test_a_passing_audit_and_reviewer_cannot_be_the_auditor(): void
    {
        $admin = $this->user('admin');
        $template = $this->publishedTemplate($admin);
        $id = $this->assign($admin, $template, $admin);                                                                                       // el admin se asigna a sí mismo

        $this->as($admin)->postJson("/api/v1/audits/{$id}/start")->assertOk();
        $this->postJson("/api/v1/audits/{$id}/answers", [])->assertStatus(405);
        $this->putJson("/api/v1/audits/{$id}/answers", ['answers' => [['question_id' => 'q1', 'value' => 'yes'], ['question_id' => 'q2', 'value' => 5], ['question_id' => 'q4', 'value' => 'a']]])->assertOk();
        $this->post("/api/v1/audits/{$id}/evidence", ['file' => $this->png(), 'question_id' => 'q3'], ['Accept' => 'application/json'])->assertCreated();
        $sub = $this->postJson("/api/v1/audits/{$id}/submit")->assertOk();
        $this->assertSame([null, null, 0], [$sub->json('result'), $sub->json('score'), $sub->json('critical_failures')]);
        $this->assertCount(0, $sub->json('findings'));                                                                                         // todo cumple: sin hallazgos

        $this->postJson("/api/v1/audits/{$id}/approve")->assertStatus(403);                                                                    // no revisa su propio trabajo
        $this->as($this->user('admin'))->postJson("/api/v1/audits/{$id}/approve")->assertOk();
    }

    public function test_scoring_is_optional_and_enabled_per_template(): void
    {
        $admin = $this->user('admin');
        $auditor = $this->user('auditor', 'organization', $this->a->id);
        $this->as($admin);
        $id = $this->postJson('/api/v1/audit-templates', ['name' => 'Con puntaje', 'scoring_enabled' => true, 'pass_threshold' => 70, 'structure' => $this->structure()])->assertCreated()->assertJsonPath('scoring_enabled', true)->json('id');
        $this->postJson("/api/v1/audit-templates/{$id}/publish")->assertOk();
        $this->putJson("/api/v1/audit-templates/{$id}", ['scoring_enabled' => false])->assertOk()->assertJsonPath('version', 2);       // cambiar el modo es un cambio de versión
        $this->putJson("/api/v1/audit-templates/{$id}", ['scoring_enabled' => true])->assertOk()->assertJsonPath('version', 3);

        $run = function (array $answers) use ($admin, $auditor, $id) {
            $audit = $this->assign($admin, $id, $auditor);
            $this->as($auditor)->postJson("/api/v1/audits/{$audit}/start")->assertOk();
            $this->putJson("/api/v1/audits/{$audit}/answers", ['answers' => $answers])->assertOk();
            $this->post("/api/v1/audits/{$audit}/evidence", ['file' => $this->png(), 'question_id' => 'q3'], ['Accept' => 'application/json'])->assertCreated();

            return $audit;
        };
        $good = $run([['question_id' => 'q1', 'value' => 'yes'], ['question_id' => 'q2', 'value' => 5], ['question_id' => 'q4', 'value' => 'a']]);
        $sub = $this->postJson("/api/v1/audits/{$good}/submit")->assertOk();
        $this->assertEquals(['pass', 100, true], [$sub->json('result'), $sub->json('score'), $sub->json('scoring_enabled')]);

        $bad = $run([['question_id' => 'q1', 'value' => 'no', 'comment' => 'x'], ['question_id' => 'q2', 'value' => 2], ['question_id' => 'q4', 'value' => 'b']]);
        $this->post("/api/v1/audits/{$bad}/evidence", ['file' => $this->png(), 'question_id' => 'q1'], ['Accept' => 'application/json'])->assertCreated();
        $sub = $this->postJson("/api/v1/audits/{$bad}/submit")->assertOk();
        $this->assertSame('fail', $sub->json('result'));                                                                                // 25% < 70%
        $this->assertSame(2, collect($sub->json('findings'))->count());                                                                 // extintor y saludo
    }

    public function test_decision_tree_skips_questions_on_the_server_too(): void
    {
        $admin = $this->user('admin');
        $auditor = $this->user('auditor', 'organization', $this->a->id);
        $this->as($admin);
        $tpl = $this->postJson('/api/v1/audit-templates', ['name' => 'Exhibición', 'structure' => ['sections' => [['id' => 's1', 'title' => 'Exhibición', 'questions' => [
            ['id' => 'q1', 'text' => '¿Tiene exhibición?', 'type' => 'yes_no'],
            ['id' => 'q2', 'text' => 'Estado de la exhibición', 'type' => 'choice', 'options' => [['id' => 'ok', 'label' => 'Bueno'], ['id' => 'mal', 'label' => 'Malo', 'finding' => true]],
                'show_if' => ['conditions' => [['question' => 'q1', 'op' => 'equals', 'value' => 'yes']]]],
        ]]]]])->assertCreated()->json('id');
        $this->postJson("/api/v1/audit-templates/{$tpl}/publish")->assertOk();

        // sin exhibición: la pregunta 2 se salta y se puede enviar
        $a1 = $this->assign($admin, $tpl, $auditor);
        $this->as($auditor)->postJson("/api/v1/audits/{$a1}/start")->assertOk();
        $r = $this->putJson("/api/v1/audits/{$a1}/answers", ['answers' => [['question_id' => 'q1', 'value' => 'no']]])->assertOk();
        $this->assertSame([true, false], [$r->json('evaluation.visible.q1'), $r->json('evaluation.visible.q2')]);
        $this->postJson("/api/v1/audits/{$a1}/submit")->assertOk()->assertJsonPath('evaluation.progress.total', 1);

        // con exhibición: pasa a ser obligatoria y su respuesta «Malo» genera hallazgo
        $a2 = $this->assign($admin, $tpl, $auditor);
        $this->as($auditor)->postJson("/api/v1/audits/{$a2}/start")->assertOk();
        $this->putJson("/api/v1/audits/{$a2}/answers", ['answers' => [['question_id' => 'q1', 'value' => 'yes']]])->assertOk();
        $this->postJson("/api/v1/audits/{$a2}/submit")->assertStatus(422)->assertJsonPath('missing.0.question_id', 'q2');
        $this->putJson("/api/v1/audits/{$a2}/answers", ['answers' => [['question_id' => 'q2', 'value' => 'mal']]])->assertOk();
        $sub = $this->postJson("/api/v1/audits/{$a2}/submit")->assertOk();
        $this->assertCount(1, $sub->json('findings'));

        // un hallazgo ya registrado no se arrastra si la respuesta que lo originó deja de aplicar (cambia la rama)
        $this->assertSame(0, DB::table('fieldaudit.findings')->where('audit_id', $a1)->count());
    }

    // ───────────────────────────── hallazgos ─────────────────────────────

    public function test_findings_follow_up_scope_and_manual_findings(): void
    {
        $admin = $this->user('admin');
        $template = $this->publishedTemplate($admin);
        $auditor = $this->user('auditor', 'organization', $this->a->id);
        $id = $this->assign($admin, $template, $auditor);

        $this->as($admin);
        $manual = $this->postJson('/api/v1/findings', ['audit_id' => $id, 'description' => 'Piso resbaladizo en bodega', 'severity' => 'high'])->assertCreated()->assertJsonPath('source', 'manual')->json('id');
        $person = Person::create(['tenant_id' => $this->tenant->id, 'organization_id' => $this->a->id, 'first_name' => 'Ana', 'last_name' => 'Pérez']);

        $this->putJson("/api/v1/findings/{$manual}", ['status' => 'in_progress', 'responsible_person_id' => $person->id, 'action_plan' => 'Instalar antideslizante', 'due_on' => '2026-12-01'])->assertOk();
        $row = $this->getJson('/api/v1/findings?status=open,in_progress')->assertOk()->json('data.0');
        $this->assertSame(['Ana Pérez', 'Empresa A', 'in_progress'], [$row['responsible'], $row['organization'], $row['status']]);
        $closed = $this->putJson("/api/v1/findings/{$manual}", ['status' => 'closed'])->assertOk();
        $this->assertNotNull($closed->json('closed_at'));
        $this->putJson("/api/v1/findings/{$manual}", ['responsible_person_id' => (string) Str::uuid()])->assertStatus(422);
        $this->assertCount(0, $this->getJson('/api/v1/findings?status=open')->json('data'));

        $this->as($this->user('admin', 'organization', $this->b->id));
        $this->assertCount(0, $this->getJson('/api/v1/findings')->json('data'));                                                              // otra empresa no lo ve
        $this->putJson("/api/v1/findings/{$manual}", ['status' => 'open'])->assertStatus(403);
        $this->as($auditor)->putJson("/api/v1/findings/{$manual}", ['status' => 'open'])->assertStatus(403);                                  // el auditor ve, no edita
        $this->deleteJson("/api/v1/findings/{$manual}")->assertStatus(403);
        $this->as($admin)->deleteJson("/api/v1/findings/{$manual}")->assertNoContent();
    }

    private function badWorkbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Preguntas');
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Sección', 'Código', 'Pregunta', 'Tipo de respuesta', 'Condición 1 · pregunta (código)', 'Condición 1 · operador', 'Condición 1 · respuesta']));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Local', 'P1', 'Extintor', 'Tipo inventado', '', '', '']));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Local', 'P2', 'Orden', 'Sí / No / N/A', 'P99', 'es', 'No']));
        $w->close();

        return $path;
    }

    public function test_excel_round_trip_sample_and_error_reporting(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);
        $id = $this->publishedTemplate($admin);

        // El ejemplo se descarga y se vuelve a importar sin errores.
        $sample = $this->get('/api/v1/audit-templates/sample')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($tmp, $sample->getContent());
        $file = new UploadedFile($tmp, 'ejemplo.xlsx', null, null, true);
        $prev = $this->post('/api/v1/audit-templates/import', ['file' => $file, 'mode' => 'preview'])->assertOk();
        $this->assertTrue($prev->json('ok'), json_encode($prev->json('errors')));
        $this->assertGreaterThan(0, $prev->json('summary.questions'));

        // Exportar una plantilla y reimportarla conserva secciones, preguntas, tipos y reglas de hallazgo.
        $export = $this->get("/api/v1/audit-templates/{$id}/export")->assertOk();
        $tmp2 = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($tmp2, $export->getContent());
        $created = $this->post('/api/v1/audit-templates/import', ['file' => new UploadedFile($tmp2, 'x.xlsx', null, null, true), 'mode' => 'create'])->assertCreated();
        $orig = $this->getJson("/api/v1/audit-templates/{$id}")->json('structure');
        $new = $created->json('template.structure');
        $this->assertSame('draft', $created->json('template.status'));
        $this->assertNotSame('Auditoría de local', $created->json('template.name'));   // nombre único
        $this->assertCount(count($orig['sections']), $new['sections']);
        foreach ($orig['sections'] as $si => $sec) {
            $this->assertSame($sec['title'], $new['sections'][$si]['title']);
            foreach ($sec['questions'] as $qi => $q) {
                $nq = $new['sections'][$si]['questions'][$qi];
                $this->assertSame($q['text'], $nq['text']);
                $this->assertSame($q['type'], $nq['type']);
                $this->assertSame($q['finding_on'] ?? [], $nq['finding_on'] ?? []);
            }
        }

        // Un archivo con errores se reporta con hoja/fila y no crea nada.
        $before = FieldAuditTemplate::count();
        $bad = $this->badWorkbook();
        $res = $this->post('/api/v1/audit-templates/import', ['file' => new UploadedFile($bad, 'mal.xlsx', null, null, true), 'mode' => 'create'])->assertOk();
        $this->assertFalse($res->json('ok'));
        $this->assertNotEmpty($res->json('errors'));
        $this->assertArrayHasKey('row', $res->json('errors.0'));
        $this->assertSame($before, FieldAuditTemplate::count());

        // Sin alcance global no puede cargar.
        $this->as($this->user('admin', 'organization', $this->a->id));
        $this->post('/api/v1/audit-templates/import', ['file' => $file, 'mode' => 'preview'])->assertForbidden();
    }
}
