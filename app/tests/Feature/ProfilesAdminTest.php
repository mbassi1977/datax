<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfilesAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
    }

    private function admin(): User
    {
        $u = User::create(['tenant_id' => $this->tenant->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        $this->give($u, DB::table('security.profiles')->where('code', 'admin')->value('id'));

        return $u;
    }

    private function give(User $u, string $profileId): void
    {
        DB::table('security.user_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'profile_id' => $profileId, 'scope_type' => 'global', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_create_update_and_delete_a_custom_profile_with_audit(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->assertContains('personas.ver', $this->getJson('/api/v1/permissions')->assertOk()->json());

        $p = $this->postJson('/api/v1/profiles', ['name' => 'Jefe de Inclusión', 'permissions' => ['personas.ver', 'proyectos.ver']])->assertCreated();
        $this->assertSame('jefe_de_inclusion', $p->json('code'));
        $this->assertFalse($p->json('is_system'));
        $id = $p->json('id');

        $this->postJson('/api/v1/profiles', ['name' => 'Jefe de Inclusión', 'permissions' => ['personas.ver']])->assertStatus(422);    // nombre repetido
        $this->postJson('/api/v1/profiles', ['name' => 'Vacío', 'permissions' => []])->assertStatus(422);
        $this->postJson('/api/v1/profiles', ['name' => 'Inventado', 'permissions' => ['cosa.rara']])->assertStatus(422);

        $this->putJson("/api/v1/profiles/{$id}", ['name' => 'Jefe', 'permissions' => ['personas.ver', 'personas.crear']])->assertOk()->assertJsonPath('permissions', ['personas.crear', 'personas.ver']);
        $event = DB::table('audit.audit_events')->where('action', 'profile_updated')->first();
        $this->assertSame(['personas.ver', 'personas.crear'], json_decode($event->new_values)->permissions_after);

        $listed = collect($this->getJson('/api/v1/profiles')->json())->firstWhere('id', $id);
        $this->assertSame([0, 'Jefe'], [$listed['users_count'], $listed['name']]);

        $this->deleteJson("/api/v1/profiles/{$id}")->assertNoContent();
        $this->assertNull(collect($this->getJson('/api/v1/profiles')->json())->firstWhere('id', $id));
    }

    public function test_system_profiles_are_read_only_and_profiles_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $system = DB::table('security.profiles')->where('code', 'colaborador')->value('id');
        $this->putJson("/api/v1/profiles/{$system}", ['name' => 'Otro', 'permissions' => ['personas.ver']])->assertStatus(404);   // no es del tenant
        $this->deleteJson("/api/v1/profiles/{$system}")->assertStatus(404);

        $id = $this->postJson('/api/v1/profiles', ['name' => 'En uso', 'permissions' => ['personas.ver']])->json('id');
        $worker = User::create(['tenant_id' => $this->tenant->id, 'email' => 'w@t.cl', 'password' => 'Secreto-12345']);
        $this->give($worker, $id);

        $this->deleteJson("/api/v1/profiles/{$id}")->assertStatus(409);
        $this->assertSame(1, collect($this->getJson('/api/v1/profiles')->json())->firstWhere('id', $id)['users_count']);
    }

    public function test_cannot_grant_permissions_one_does_not_have(): void
    {
        // un perfil que puede administrar perfiles pero solo ve personas
        $this->actingAs($this->admin(), 'sanctum');
        $limited = $this->postJson('/api/v1/profiles', ['name' => 'Gestor de perfiles', 'permissions' => ['perfiles.ver', 'perfiles.crear', 'perfiles.editar', 'personas.ver']])->assertCreated()->json('id');
        $user = User::create(['tenant_id' => $this->tenant->id, 'email' => 'l@t.cl', 'password' => 'Secreto-12345']);
        $this->give($user, $limited);

        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v1/profiles', ['name' => 'Escalada', 'permissions' => ['personas.ver', 'usuarios.eliminar']])->assertStatus(403);
        $ok = $this->postJson('/api/v1/profiles', ['name' => 'Permitida', 'permissions' => ['personas.ver']])->assertCreated()->json('id');
        $this->putJson("/api/v1/profiles/{$ok}", ['name' => 'Permitida', 'permissions' => ['personas.ver', 'usuarios.crear']])->assertStatus(403);
    }

    public function test_profiles_of_other_tenants_are_isolated(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $other = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $foreign = (string) Str::uuid();
        DB::table('security.profiles')->insert(['id' => $foreign, 'tenant_id' => $other->id, 'code' => 'ajeno', 'name' => 'Ajeno', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNull(collect($this->getJson('/api/v1/profiles')->json())->firstWhere('id', $foreign));
        $this->putJson("/api/v1/profiles/{$foreign}", ['name' => 'x', 'permissions' => ['personas.ver']])->assertStatus(404);
    }
}
