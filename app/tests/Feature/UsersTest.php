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

class UsersTest extends TestCase
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

    private function user(string $profile, string $scopeType = 'global', ?string $scopeId = null, ?string $orgId = null): User
    {
        $user = User::create(['tenant_id' => $this->tenant->id, 'organization_id' => $orgId, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        $this->attach($user, $profile, $scopeType, $scopeId);

        return $user;
    }

    private function attach(User $user, string $profile, string $scopeType, ?string $scopeId): void
    {
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Simula una petición nueva con un token real (el guard cachea el usuario entre llamadas). */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_admin_manages_users_and_passwords_are_never_exposed(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum');

        $this->postJson('/api/v1/users', ['email' => 'nuevo@t.cl', 'password' => 'corta'])->assertStatus(422);
        $id = $this->postJson('/api/v1/users', ['email' => 'nuevo@t.cl', 'password' => 'Clave-segura-1', 'organization_id' => $this->a->id])
            ->assertCreated()->assertJsonMissingPath('password')->json('id');
        $this->postJson('/api/v1/users', ['email' => 'nuevo@t.cl', 'password' => 'Clave-segura-1'])->assertStatus(422);

        $this->getJson('/api/v1/users')->assertOk()->assertJsonMissingPath('data.0.password');
        $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => 'nuevo@t.cl', 'password' => 'Clave-segura-1'])->assertOk();
        $event = DB::table('audit.audit_events')->where('entity_id', $id)->where('action', 'created')->first();
        $this->assertArrayNotHasKey('password', (array) json_decode($event->new_values));
    }

    public function test_cannot_delete_self_nor_set_superadmin(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
        $id = $this->postJson('/api/v1/users', ['email' => 'x@t.cl', 'password' => 'Clave-segura-1', 'is_superadmin' => true])->json('id');
        $this->assertFalse((bool) User::find($id)->is_superadmin);
    }

    public function test_profile_assignment_blocks_privilege_escalation(): void
    {
        $scoped = $this->user('admin', 'organization', $this->a->id, $this->a->id);
        $target = $this->user('colaborador', 'organization', $this->a->id, $this->a->id);
        $this->actingAs($scoped, 'sanctum');

        $url = "/api/v1/users/{$target->id}/profiles";
        $this->postJson($url, ['profile' => 'admin', 'scope_type' => 'global'])->assertStatus(403);
        $this->postJson($url, ['profile' => 'admin', 'scope_type' => 'organization', 'scope_id' => $this->b->id])->assertStatus(403);
        $this->postJson($url, ['profile' => 'superadmin', 'scope_type' => 'organization', 'scope_id' => $this->a->id])->assertStatus(403);
        $this->postJson($url, ['profile' => 'admin', 'scope_type' => 'organization', 'scope_id' => (string) Str::uuid()])->assertStatus(422);

        $id = $this->postJson($url, ['profile' => 'admin', 'scope_type' => 'organization', 'scope_id' => $this->a->id, 'valid_until' => now()->addMonth()->toDateString()])
            ->assertCreated()->json('id');
        $this->getJson($url)->assertOk()->assertJsonCount(2);
        $this->deleteJson("$url/$id")->assertNoContent();
        $this->assertAuditAction('profile_assigned');
        $this->assertAuditAction('profile_revoked');
    }

    public function test_scoped_admin_cannot_manage_users_of_other_organizations(): void
    {
        $scoped = $this->user('admin', 'organization', $this->a->id, $this->a->id);
        $other = $this->user('colaborador', 'organization', $this->b->id, $this->b->id);
        $this->actingAs($scoped, 'sanctum');

        $names = collect($this->getJson('/api/v1/users')->json('data'))->pluck('id');
        $this->assertTrue($names->contains($scoped->id));
        $this->assertFalse($names->contains($other->id));
        $this->putJson("/api/v1/users/{$other->id}", ['status' => 'suspended'])->assertStatus(403);
        $this->postJson("/api/v1/users/{$other->id}/profiles", ['profile' => 'colaborador', 'scope_type' => 'organization', 'scope_id' => $this->a->id])->assertStatus(403);
    }

    public function test_user_without_permission_cannot_assign_profiles(): void
    {
        $worker = $this->user('colaborador');
        $this->actingAs($worker, 'sanctum')
            ->postJson("/api/v1/users/{$worker->id}/profiles", ['profile' => 'admin', 'scope_type' => 'global'])->assertStatus(403);
    }

    public function test_suspending_a_user_kills_their_tokens(): void
    {
        $admin = $this->user('admin');
        $victim = $this->user('admin');
        $victimToken = $victim->createToken('t')->plainTextToken;

        $this->asToken($victimToken)->getJson('/api/v1/me')->assertOk();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$victim->id}", ['status' => 'suspended'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')->asToken($victimToken)->getJson('/api/v1/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => $victim->email, 'password' => 'Secreto-12345'])->assertStatus(401);
    }

    public function test_change_own_password(): void
    {
        $user = $this->user('colaborador');
        $current = $user->createToken('actual')->plainTextToken;
        $other = $user->createToken('otro')->plainTextToken;

        $this->asToken($current)->postJson('/api/v1/me/password', ['current_password' => 'mala', 'password' => 'Nueva-clave-99', 'password_confirmation' => 'Nueva-clave-99'])->assertStatus(422);
        $this->asToken($current)->postJson('/api/v1/me/password', ['current_password' => 'Secreto-12345', 'password' => 'Nueva-clave-99', 'password_confirmation' => 'Nueva-clave-99'])->assertOk();

        $this->asToken($current)->getJson('/api/v1/me')->assertOk();                 // la sesión actual sigue
        $this->asToken($other)->getJson('/api/v1/me')->assertStatus(401);            // las demás se cierran
        $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => $user->email, 'password' => 'Nueva-clave-99'])->assertOk();
    }

    public function test_profile_catalog_lists_permissions(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum');
        $admin = collect($this->getJson('/api/v1/profiles')->assertOk()->json())->firstWhere('code', 'admin');
        $this->assertContains('usuarios.editar', $admin['permissions']);
    }

    private function assertAuditAction(string $action): void
    {
        $this->assertDatabaseHas('audit.audit_events', ['action' => $action]);
    }
}
