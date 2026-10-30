<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SecuritySeeder::class);
        $this->seed(CatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
    }

    private function user(string $profile = 'admin', string $scope = 'global', ?string $scopeId = null, ?Tenant $tenant = null): User
    {
        $u = User::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
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

    public function test_system_catalogs_are_listed_and_read_only(): void
    {
        $this->as($this->user());
        $types = collect($this->getJson('/api/v1/catalogs')->assertOk()->json());
        $this->assertEqualsCanonicalizing(['regiones', 'nacionalidades', 'industrias', 'sexo', 'cargos'], $types->pluck('key')->all());
        $this->assertSame(16, $types->firstWhere('key', 'regiones')['items_count']);
        $this->assertFalse($types->firstWhere('key', 'regiones')['allow_tenant_items']);   // regiones: cerrado
        $this->assertTrue($types->firstWhere('key', 'cargos')['allow_tenant_items']);

        $region = collect($this->getJson('/api/v1/catalogs/regiones/items')->json())->firstWhere('code', 'RM');
        $this->assertSame(['Metropolitana de Santiago', true], [$region['label'], $region['is_system']]);

        $this->putJson("/api/v1/catalogs/regiones/items/{$region['id']}", ['label' => 'x'])->assertStatus(403);
        $this->deleteJson("/api/v1/catalogs/regiones/items/{$region['id']}")->assertStatus(403);
        $this->postJson('/api/v1/catalogs/regiones/items', ['label' => 'Región 17'])->assertStatus(403);   // no admite entradas propias
        $this->putJson('/api/v1/catalogs/regiones', ['label' => 'Otra'])->assertStatus(403);
        $this->deleteJson('/api/v1/catalogs/regiones')->assertStatus(403);
    }

    public function test_tenant_adds_its_own_entries_to_an_open_system_catalog(): void
    {
        $this->as($this->user());
        $id = $this->postJson('/api/v1/catalogs/cargos/items', ['label' => 'Analista de Inclusión'])->assertCreated()->assertJsonPath('code', 'analista_de_inclusion')->json('id');
        $this->postJson('/api/v1/catalogs/cargos/items', ['label' => 'Analista de Inclusión'])->assertStatus(422);   // código repetido
        $this->postJson('/api/v1/catalogs/cargos/items', ['label' => 'Jefe', 'code' => 'jefe', 'sort' => 5])->assertCreated();
        $this->putJson("/api/v1/catalogs/cargos/items/{$id}", ['label' => 'Analista Senior', 'active' => false])->assertOk();

        $this->assertSame(['Jefe'], collect($this->getJson('/api/v1/catalogs/cargos/options')->json())->pluck('label')->all());   // las inactivas no se ofrecen
        $this->assertCount(2, $this->getJson('/api/v1/catalogs/cargos/items')->json());                                            // pero el admin las ve

        $this->deleteJson("/api/v1/catalogs/cargos/items/{$id}")->assertNoContent();
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'updated', 'entity_id' => $id]);
    }

    public function test_catalog_builder_creates_custom_types_isolated_per_tenant(): void
    {
        $this->as($this->user());
        $t = $this->postJson('/api/v1/catalogs', ['label' => 'Tipos de Discapacidad', 'description' => 'Según Ley 21.275'])->assertCreated();
        $this->assertSame('tipos_de_discapacidad', $t->json('key'));
        $this->postJson('/api/v1/catalogs', ['label' => 'Tipos de Discapacidad'])->assertCreated()->assertJsonPath('key', 'tipos_de_discapacidad_2');

        $this->postJson('/api/v1/catalogs/tipos_de_discapacidad/items', ['label' => 'Visual'])->assertCreated();
        $this->postJson('/api/v1/catalogs/tipos_de_discapacidad/items', ['label' => 'Auditiva'])->assertCreated();
        $this->putJson('/api/v1/catalogs/tipos_de_discapacidad', ['label' => 'Discapacidades'])->assertOk();

        $other = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $this->as($this->user('admin', 'global', null, $other));
        $this->assertNotContains('tipos_de_discapacidad', collect($this->getJson('/api/v1/catalogs')->json())->pluck('key')->all());
        $this->getJson('/api/v1/catalogs/tipos_de_discapacidad/options')->assertStatus(404);

        $this->as($this->user());
        $this->deleteJson('/api/v1/catalogs/tipos_de_discapacidad')->assertNoContent();
        $this->getJson('/api/v1/catalogs/tipos_de_discapacidad/items')->assertStatus(404);
        $this->assertSame(0, DB::table('core.catalogs')->where('catalog', 'tipos_de_discapacidad')->whereNull('deleted_at')->count());
    }

    public function test_permissions_and_scope(): void
    {
        $this->as($this->user('colaborador'));
        $this->getJson('/api/v1/catalogs')->assertStatus(403);                                  // sin catalogos.ver
        $this->getJson('/api/v1/catalogs/regiones/options')->assertOk()->assertJsonCount(16);   // pero los formularios sí pueden leer las opciones

        $this->as($this->user('admin', 'organization', (string) Str::uuid()));
        $this->postJson('/api/v1/catalogs', ['label' => 'Acotado'])->assertStatus(403);         // escribir exige alcance global
        $this->postJson('/api/v1/catalogs/cargos/items', ['label' => 'X'])->assertStatus(403);
    }
}
