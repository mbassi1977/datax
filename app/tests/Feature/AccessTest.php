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

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
    }

    private function org(string $name, ?Tenant $tenant = null): Organization
    {
        return Organization::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'legal_name' => $name]);
    }

    private function user(string $profile, string $scopeType = 'global', ?string $scopeId = null, ?Tenant $tenant = null): User
    {
        $user = User::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'email' => Str::random(6).'@t.cl', 'password' => 'secreto-123']);
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_login_returns_token_and_bad_password_is_rejected_and_audited(): void
    {
        $user = $this->user('admin');

        $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => $user->email, 'password' => 'mala'])->assertStatus(401);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'login_failed']);

        $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => $user->email, 'password' => 'secreto-123'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'login', 'user_id' => $user->id]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/organizations')->assertStatus(401);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->user('colaborador'), 'sanctum')->getJson('/api/v1/organizations')->assertStatus(403);
    }

    public function test_scope_limits_visible_organizations(): void
    {
        $a = $this->org('A');
        $b = $this->org('B');
        $admin = $this->user('admin', 'organization', $a->id);

        $this->actingAs($admin, 'sanctum');
        $names = collect($this->getJson('/api/v1/organizations')->assertOk()->json('data'))->pluck('legal_name');
        $this->assertEquals(['A'], $names->all());
        $this->getJson("/api/v1/organizations/{$b->id}")->assertStatus(403);
        $this->putJson("/api/v1/organizations/{$b->id}", ['legal_name' => 'X'])->assertStatus(403);
        $this->postJson('/api/v1/organizations', ['legal_name' => 'Nueva'])->assertStatus(403);
    }

    public function test_holding_scope_covers_its_organizations(): void
    {
        $holdingId = (string) Str::uuid();
        DB::table('core.holdings')->insert(['id' => $holdingId, 'tenant_id' => $this->tenant->id, 'name' => 'H', 'created_at' => now(), 'updated_at' => now()]);
        $inside = $this->org('Dentro');
        $inside->update(['holding_id' => $holdingId]);
        $this->org('Fuera');

        $names = collect($this->actingAs($this->user('admin', 'holding', $holdingId), 'sanctum')
            ->getJson('/api/v1/organizations')->json('data'))->pluck('legal_name');
        $this->assertEquals(['Dentro'], $names->all());
    }

    public function test_tenants_are_isolated(): void
    {
        $other = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $foreign = $this->org('Ajena', $other);

        $this->actingAs($this->user('admin'), 'sanctum');
        $this->assertCount(0, $this->getJson('/api/v1/organizations')->json('data'));
        $this->getJson("/api/v1/organizations/{$foreign->id}")->assertStatus(404);
    }

    public function test_global_admin_creates_updates_and_changes_are_audited(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum');

        $id = $this->postJson('/api/v1/organizations', ['legal_name' => 'Nueva', 'rut' => '1-9'])->assertCreated()->json('id');
        $this->postJson('/api/v1/organizations', ['legal_name' => 'Dup', 'rut' => '1-9'])->assertStatus(422);

        $this->putJson("/api/v1/organizations/{$id}", ['legal_name' => 'Renombrada'])->assertOk();

        $event = DB::table('audit.audit_events')->where('entity_id', $id)->where('action', 'updated')->first();
        $this->assertNotNull($event);
        $this->assertEquals($admin->id, $event->user_id);
        $this->assertEquals('Nueva', json_decode($event->old_values)->legal_name);
        $this->assertEquals('Renombrada', json_decode($event->new_values)->legal_name);
    }

    public function test_me_lists_permissions_and_profile_header_restricts_them(): void
    {
        $user = $this->user('admin');
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'profile_id' => DB::table('security.profiles')->where('code', 'colaborador')->value('id'),
            'scope_type' => 'global', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user, 'sanctum');

        $this->assertContains('organizaciones.crear', $this->getJson('/api/v1/me')->json('permissions'));
        $this->assertNotContains('organizaciones.crear', $this->getJson('/api/v1/me', ['X-Profile' => 'colaborador'])->json('permissions'));
        $this->getJson('/api/v1/organizations', ['X-Profile' => 'colaborador'])->assertStatus(403);
        $this->getJson('/api/v1/organizations', ['X-Profile' => 'superadmin'])->assertStatus(403);
    }
}
