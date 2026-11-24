<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldSurveyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $org;
    private array $branches = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        $this->org = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Empresa A']);
        foreach (['Centro', 'Norte', 'Sur'] as $n) {
            $id = (string) Str::uuid();
            DB::table('core.branches')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'organization_id' => $this->org->id, 'name' => $n, 'created_at' => now(), 'updated_at' => now()]);
            $this->branches[$n] = $id;
        }
    }

    private function user(string $profile): User
    {
        $u = User::create(['tenant_id' => $this->tenant->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        DB::table('security.user_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => 'global', 'scope_id' => null, 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function template(User $admin): string
    {
        $this->as($admin);
        $id = $this->postJson('/api/v1/audit-templates', ['name' => 'Levantamiento precios', 'structure' => ['sections' => [['id' => 's1', 'title' => 'Góndola', 'questions' => [
            ['id' => 'q1', 'text' => 'Precio visible', 'type' => 'yes_no'], ['id' => 'q2', 'text' => 'Facing', 'type' => 'number'],
        ]]]]])->assertCreated()->json('id');
        $this->postJson("/api/v1/audit-templates/{$id}/publish")->assertOk();

        return $id;
    }

    public function test_survey_assignment_progress_and_close(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->user('auditor');
        $tpl = $this->template($admin);

        $sid = $this->postJson('/api/v1/surveys', ['template_id' => $tpl, 'organization_id' => $this->org->id, 'name' => 'Precios noviembre'])->assertCreated()->json('id');
        $r = $this->postJson("/api/v1/surveys/{$sid}/assign", ['assignments' => [
            ['branch_id' => $this->branches['Centro'], 'auditor_id' => $c1->id], ['branch_id' => $this->branches['Norte'], 'auditor_id' => $c1->id], ['branch_id' => (string) Str::uuid(), 'auditor_id' => $c1->id],
        ]])->assertCreated();
        $this->assertCount(2, $r->json('created'));
        $this->assertCount(1, $r->json('skipped'));
        $this->postJson("/api/v1/surveys/{$sid}/assign", ['assignments' => [['branch_id' => $this->branches['Centro'], 'auditor_id' => $c1->id]]])->assertCreated()->assertJsonCount(1, 'skipped');   // no se duplica
        $this->getJson("/api/v1/surveys/{$sid}")->assertJsonPath('progress.total', 2)->assertJsonPath('progress.percent', 0);

        // el colector no gestiona levantamientos
        $this->as($c1)->getJson('/api/v1/surveys')->assertForbidden();
        $this->as($admin)->postJson("/api/v1/surveys/{$sid}/close")->assertOk()->assertJsonPath('status', 'closed');
        $this->getJson("/api/v1/surveys/{$sid}")->assertJsonPath('progress.total', 0);                                // sin ejecutar: canceladas
        $this->postJson("/api/v1/surveys/{$sid}/assign", ['assignments' => [['branch_id' => $this->branches['Sur'], 'auditor_id' => $c1->id]]])->assertStatus(409);
    }

    public function test_offline_sync_is_idempotent_and_reports_rejections(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->user('auditor');
        $c2 = $this->user('auditor');
        $tpl = $this->template($admin);
        $sid = $this->postJson('/api/v1/surveys', ['template_id' => $tpl, 'organization_id' => $this->org->id, 'name' => 'Precios'])->json('id');
        $ids = $this->postJson("/api/v1/surveys/{$sid}/assign", ['assignments' => [
            ['branch_id' => $this->branches['Centro'], 'auditor_id' => $c1->id], ['branch_id' => $this->branches['Norte'], 'auditor_id' => $c2->id],
        ]])->json('created');
        [$mine, $other] = $ids;

        // pull: solo lo propio, con plantilla y sucursal
        $pull = $this->as($c1)->getJson('/api/v1/sync/field/pull')->assertOk();
        $pull->assertJsonCount(1, 'audits')->assertJsonPath('audits.0.id', $mine)->assertJsonPath('audits.0.branch.name', 'Centro');
        $this->assertNotEmpty($pull->json('audits.0.structure.sections'));
        $this->assertSame([$mine], $pull->json('active_ids'));
        $this->getJson('/api/v1/sync/field/pull?since='.urlencode(now()->addMinute()->toIso8601String()))->assertJsonCount(0, 'audits')->assertJsonPath('active_ids.0', $mine);

        // push offline: start + respuestas (una inválida) + una operación sobre lo ajeno
        $ops = [
            ['op_id' => 'op-1', 'type' => 'start', 'audit_id' => $mine, 'lat' => -33.4, 'lng' => -70.6],
            ['op_id' => 'op-2', 'type' => 'answers', 'audit_id' => $mine, 'answers' => [['question_id' => 'q1', 'value' => 'yes', 'client_updated_at' => '2026-11-10T10:00:00Z'], ['question_id' => 'q2', 'value' => 12, 'client_updated_at' => '2026-11-10T10:00:05Z']]],
            ['op_id' => 'op-3', 'type' => 'answers', 'audit_id' => $mine, 'answers' => [['question_id' => 'q2', 'value' => 'abc']]],
            ['op_id' => 'op-4', 'type' => 'start', 'audit_id' => $other],
        ];
        $res = $this->postJson('/api/v1/sync/field/push', ['ops' => $ops])->assertOk()->json('results');
        $this->assertSame(['ok', 'ok', 'rejected', 'rejected'], array_column($res, 'outcome'));
        $this->assertSame(404, $res[3]['status']);
        $this->assertSame('in_progress', DB::table('fieldaudit.audits')->where('id', $mine)->value('status'));
        $this->assertSame(2, DB::table('fieldaudit.answers')->where('audit_id', $mine)->count());

        // reenviar lo mismo (se perdió la respuesta): idéntico resultado, sin duplicar
        $again = $this->postJson('/api/v1/sync/field/push', ['ops' => $ops])->assertOk()->json('results');
        $this->assertSame(['ok', 'ok', 'rejected', 'rejected'], array_column($again, 'outcome'));
        $this->assertTrue($again[1]['duplicate']);
        $this->assertSame(1, DB::table('fieldaudit.sync_ops')->where('op_id', 'op-2')->count());

        // una respuesta vieja que llega tarde no pisa a la nueva
        $late = $this->postJson('/api/v1/sync/field/push', ['ops' => [['op_id' => 'op-5', 'type' => 'answers', 'audit_id' => $mine, 'answers' => [['question_id' => 'q2', 'value' => 1, 'client_updated_at' => '2026-11-10T09:00:00Z']]]]])->json('results.0');
        $this->assertSame(['q2'], $late['result']['skipped']);
        $this->assertEquals(12, DB::table('fieldaudit.answers')->where('audit_id', $mine)->where('question_id', 'q2')->value(DB::raw("value #>> '{}'")));

        // foto offline con client_id (idempotente) y envío final
        $im = imagecreatetruecolor(400, 300);
        ob_start(); imagepng($im); $png = ob_get_clean();
        $up = fn () => $this->post("/api/v1/audits/{$mine}/evidence", ['file' => UploadedFile::fake()->createWithContent('f.png', $png), 'client_id' => 'ph-1', 'question_id' => 'q1'], ['Accept' => 'application/json']);
        $a = $up()->assertCreated()->json('id');
        $this->assertSame($a, $up()->json('id'));
        $sub = $this->postJson('/api/v1/sync/field/push', ['ops' => [['op_id' => 'op-6', 'type' => 'submit', 'audit_id' => $mine]]])->json('results.0');
        $this->assertSame('ok', $sub['outcome']);
        $this->assertSame('submitted', DB::table('fieldaudit.audits')->where('id', $mine)->value('status'));
        $this->assertSame('ok', $this->postJson('/api/v1/sync/field/push', ['ops' => [['op_id' => 'op-7', 'type' => 'submit', 'audit_id' => $mine]]])->json('results.0.outcome'));   // ya enviada

        // lo enviado sale del dispositivo
        $this->getJson('/api/v1/sync/field/pull')->assertJsonCount(0, 'active_ids');
    }

    public function test_submit_missing_required_is_rejected(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->user('auditor');
        $tpl = $this->template($admin);
        $sid = $this->postJson('/api/v1/surveys', ['template_id' => $tpl, 'organization_id' => $this->org->id, 'name' => 'X'])->json('id');
        $mine = $this->postJson("/api/v1/surveys/{$sid}/assign", ['assignments' => [['branch_id' => $this->branches['Sur'], 'auditor_id' => $c1->id]]])->json('created.0');
        $r = $this->as($c1)->postJson('/api/v1/sync/field/push', ['ops' => [['op_id' => 's1', 'type' => 'start', 'audit_id' => $mine], ['op_id' => 's2', 'type' => 'submit', 'audit_id' => $mine]]])->json('results');
        $this->assertSame(['ok', 'rejected'], array_column($r, 'outcome'));
        $this->assertSame(422, $r[1]['status']);
    }

    public function test_field_user_reads_and_creates_branches_according_to_permissions(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->user('auditor');                       // el perfil auditor no tiene sucursales.*
        $tpl = $this->template($admin);
        $sid = $this->postJson('/api/v1/surveys', ['template_id' => $tpl, 'organization_id' => $this->org->id, 'name' => 'Precios'])->json('id');
        $this->postJson("/api/v1/surveys/{$sid}/assign", ['assignments' => [['branch_id' => $this->branches['Centro'], 'auditor_id' => $c1->id]]]);

        $p = $this->as($c1)->getJson('/api/v1/sync/field/pull')->assertOk();
        $p->assertJsonPath('can.read_branches', false)->assertJsonPath('can.create_branches', false)->assertJsonCount(0, 'branches')->assertJsonCount(1, 'open_surveys');
        $op = ['op_id' => 'b1', 'type' => 'branch', 'organization_id' => $this->org->id, 'name' => 'Local nuevo', 'survey_id' => $sid];
        $this->postJson('/api/v1/sync/field/push', ['ops' => [$op]])->assertJsonPath('results.0.outcome', 'rejected')->assertJsonPath('results.0.status', 403);
        $this->assertSame(0, DB::table('core.branches')->where('name', 'Local nuevo')->count());

        // con permisos de lectura y alta de sucursales
        $perms = DB::table('security.permissions')->whereIn('code', ['sucursales.ver', 'sucursales.crear'])->pluck('id');
        $pid = DB::table('security.profiles')->where('code', 'auditor')->value('id');
        foreach ($perms as $perm) {
            DB::table('security.profile_permissions')->insertOrIgnore(['profile_id' => $pid, 'permission_id' => $perm]);
        }
        $this->app['auth']->forgetGuards();
        $p = $this->as($c1)->getJson('/api/v1/sync/field/pull')->assertJsonPath('can.create_branches', true);
        $this->assertCount(3, $p->json('branches'));
        $op['op_id'] = 'b2';                                  // una operación rechazada queda rechazada: el reintento lleva otro op_id
        $r = $this->postJson('/api/v1/sync/field/push', ['ops' => [$op]])->assertJsonPath('results.0.outcome', 'ok')->json('results.0.result');
        $this->assertSame('Local nuevo', $r['branch']['name']);
        $this->assertSame($c1->id, DB::table('fieldaudit.audits')->where('id', $r['audit_id'])->value('auditor_id'));
        $this->postJson('/api/v1/sync/field/push', ['ops' => [$op]])->assertJsonPath('results.0.duplicate', true);       // reintento: no duplica
        $this->assertSame(1, DB::table('core.branches')->where('name', 'Local nuevo')->count());
    }
}
