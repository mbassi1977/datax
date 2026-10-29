<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResourcesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $a;
    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        $this->a = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'A']);
        $this->b = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'B']);
    }

    private function admin(string $scopeType = 'global', ?string $scopeId = null): User
    {
        $user = User::create(['tenant_id' => $this->tenant->id, 'email' => Str::random(6).'@t.cl', 'password' => 'secreto-123']);
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'profile_id' => DB::table('security.profiles')->where('code', 'admin')->value('id'),
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_global_admin_builds_the_organizational_structure(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $org = $this->a->id;

        $branch = $this->postJson('/api/v1/branches', ['organization_id' => $org, 'name' => 'Casa Matriz'])->assertCreated()->json('id');
        $parent = $this->postJson('/api/v1/areas', ['organization_id' => $org, 'name' => 'Gerencia', 'branch_id' => $branch])->assertCreated()->json('id');
        $area = $this->postJson('/api/v1/areas', ['organization_id' => $org, 'name' => 'RRHH', 'parent_id' => $parent])->assertCreated()->json('id');
        $cc = $this->postJson('/api/v1/cost-centers', ['organization_id' => $org, 'code' => 'CC-1', 'name' => 'Gestión', 'area_id' => $area])->assertCreated()->json('id');
        $person = $this->postJson('/api/v1/people', ['organization_id' => $org, 'first_name' => 'Ana', 'rut' => '1-9', 'roles' => ['trabajador', 'relator'], 'area_id' => $area])->assertCreated()->json('id');
        $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => 'Diagnóstico', 'code' => 'P-1', 'cost_center_id' => $cc, 'manager_id' => $person, 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'])->assertCreated();

        $this->getJson('/api/v1/people?q=ana')->assertOk()->assertJsonPath('data.0.roles', ['trabajador', 'relator']);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'created', 'entity_type' => \App\Models\Project::class]);
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $org = $this->a->id;

        $this->postJson('/api/v1/cost-centers', ['organization_id' => $org, 'code' => 'X', 'name' => 'uno'])->assertCreated();
        $this->postJson('/api/v1/cost-centers', ['organization_id' => $org, 'code' => 'X', 'name' => 'dos'])->assertStatus(422);
        $this->postJson('/api/v1/cost-centers', ['organization_id' => $this->b->id, 'code' => 'X', 'name' => 'otra org'])->assertCreated();

        $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => 'P', 'starts_on' => '2026-05-01', 'ends_on' => '2026-01-01'])->assertStatus(422);
        $this->postJson('/api/v1/projects', ['organization_id' => (string) Str::uuid(), 'name' => 'P'])->assertStatus(422);
        $this->postJson('/api/v1/people', ['organization_id' => $org, 'first_name' => 'X', 'roles' => ['inventado']])->assertStatus(422);

        $areaId = $this->postJson('/api/v1/areas', ['organization_id' => $org, 'name' => 'Sola'])->json('id');
        $this->putJson("/api/v1/areas/{$areaId}", ['parent_id' => $areaId])->assertStatus(422);
    }

    public function test_cannot_reference_records_from_another_organization(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $branchB = $this->postJson('/api/v1/branches', ['organization_id' => $this->b->id, 'name' => 'Suc B'])->json('id');

        $this->postJson('/api/v1/areas', ['organization_id' => $this->a->id, 'name' => 'Cruce', 'branch_id' => $branchB])->assertStatus(422);
    }

    public function test_scoped_user_only_touches_own_organization(): void
    {
        $global = $this->admin();
        $this->actingAs($global, 'sanctum');
        $projB = $this->postJson('/api/v1/projects', ['organization_id' => $this->b->id, 'name' => 'De B'])->json('id');
        $this->postJson('/api/v1/projects', ['organization_id' => $this->a->id, 'name' => 'De A'])->assertCreated();
        $orphan = $this->postJson('/api/v1/people', ['first_name' => 'Sin empresa'])->assertCreated()->json('id');

        $this->actingAs($this->admin('organization', $this->a->id), 'sanctum');
        $this->assertEquals(['De A'], collect($this->getJson('/api/v1/projects')->json('data'))->pluck('name')->all());
        $this->getJson("/api/v1/projects/{$projB}")->assertStatus(403);
        $this->deleteJson("/api/v1/projects/{$projB}")->assertStatus(403);
        $this->postJson('/api/v1/projects', ['organization_id' => $this->b->id, 'name' => 'Intruso'])->assertStatus(403);

        // personas sin organización solo las ve el alcance global
        $this->assertCount(0, $this->getJson('/api/v1/people')->json('data'));
        $this->getJson("/api/v1/people/{$orphan}")->assertStatus(403);
        $this->postJson('/api/v1/people', ['first_name' => 'Huérfana'])->assertStatus(403);

        // no puede mover un registro propio hacia una organización ajena
        $mine = $this->getJson('/api/v1/projects')->json('data.0.id');
        $this->putJson("/api/v1/projects/{$mine}", ['organization_id' => $this->b->id])->assertStatus(403);
    }

    public function test_deleted_records_disappear_but_are_audited(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $id = $this->postJson('/api/v1/branches', ['organization_id' => $this->a->id, 'name' => 'Temporal'])->json('id');

        $this->deleteJson("/api/v1/branches/{$id}")->assertNoContent();
        $this->getJson("/api/v1/branches/{$id}")->assertStatus(404);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'deleted', 'entity_id' => $id]);
    }
}
