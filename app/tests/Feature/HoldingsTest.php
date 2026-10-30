<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HoldingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
    }

    private function user(string $profile = 'admin', string $scope = 'global', ?string $scopeId = null): User
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

    public function test_crud_organizations_membership_and_deletion_rules(): void
    {
        $this->as($this->user());
        $h = $this->postJson('/api/v1/holdings', ['name' => 'Grupo Norte'])->assertCreated()->assertJsonPath('organizations_count', 0)->json('id');
        $this->postJson('/api/v1/holdings', ['name' => 'Grupo Norte'])->assertStatus(422);               // nombre repetido

        $org = $this->postJson('/api/v1/organizations', ['legal_name' => 'Filial 1', 'holding_id' => $h])->assertCreated()->json('id');
        $this->getJson("/api/v1/holdings/{$h}")->assertOk()->assertJsonPath('organizations_count', 1);
        $this->putJson("/api/v1/holdings/{$h}", ['name' => 'Grupo Norte SpA'])->assertOk()->assertJsonPath('name', 'Grupo Norte SpA');

        $this->deleteJson("/api/v1/holdings/{$h}")->assertStatus(409);                                  // tiene filiales
        $this->putJson("/api/v1/organizations/{$org}", ['holding_id' => null])->assertOk();
        $this->deleteJson("/api/v1/holdings/{$h}")->assertNoContent();
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'deleted', 'entity_id' => $h]);
    }

    public function test_holding_scope_covers_its_organizations_and_organization_must_be_in_the_same_tenant(): void
    {
        $this->as($this->user());
        $h = Holding::create(['tenant_id' => $this->tenant->id, 'name' => 'G']);
        $inside = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Dentro', 'holding_id' => $h->id]);
        Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Fuera']);

        // un usuario con perfil de alcance "holding" ve el holding y solo sus organizaciones
        $scoped = $this->user('admin', 'holding', $h->id);
        $this->as($scoped);
        $this->assertSame([$h->id], collect($this->getJson('/api/v1/holdings')->json('data'))->pluck('id')->all());
        $this->assertSame(['Dentro'], collect($this->getJson('/api/v1/organizations')->json('data'))->pluck('legal_name')->all());
        $this->putJson("/api/v1/holdings/{$h->id}", ['name' => 'X'])->assertStatus(403);                // escribir exige alcance global
        $this->postJson('/api/v1/holdings', ['name' => 'Otro'])->assertStatus(403);

        $otherTenant = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $foreign = Holding::create(['tenant_id' => $otherTenant->id, 'name' => 'Ajeno']);
        $this->getJson("/api/v1/holdings/{$foreign->id}")->assertStatus(404);
        $this->as($this->user())->postJson('/api/v1/organizations', ['legal_name' => 'Cruce', 'holding_id' => $foreign->id])->assertStatus(422);

        $this->assertNotNull($inside);
    }

    public function test_requires_permission(): void
    {
        $this->as($this->user('colaborador'))->getJson('/api/v1/holdings')->assertStatus(403);
    }
}
