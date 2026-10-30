<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditViewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SecuritySeeder::class);
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

    public function test_events_are_listed_filtered_and_detailed_with_old_and_new_values(): void
    {
        $admin = $this->user();
        $this->as($admin);
        $org = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Antes']);
        $org->update(['legal_name' => 'Después']);

        $all = $this->getJson('/api/v1/audit-events')->assertOk();
        $this->assertGreaterThanOrEqual(2, $all->json('total'));

        $upd = $this->getJson('/api/v1/audit-events?action=updated&entity_type='.urlencode(Organization::class))->assertOk();
        $this->assertSame(1, $upd->json('total'));
        $detail = $this->getJson('/api/v1/audit-events/'.$upd->json('data.0.id'))->assertOk();
        $this->assertSame(['Antes', 'Después'], [$detail->json('old_values.legal_name'), $detail->json('new_values.legal_name')]);
        $this->assertSame($admin->email, $detail->json('user_email'));

        $this->assertSame(0, $this->getJson('/api/v1/audit-events?action=login')->json('total'));
        $this->assertGreaterThan(0, $this->getJson('/api/v1/audit-events?q=Organization')->json('total'));
        $this->getJson('/api/v1/audit-events?from=no-es-fecha')->assertStatus(422);

        $meta = $this->getJson('/api/v1/audit-events/meta')->assertOk();
        $this->assertContains('updated', $meta->json('actions'));
        $this->assertContains(Organization::class, $meta->json('entity_types'));
        $this->assertContains($admin->email, collect($meta->json('users'))->pluck('email')->all());
    }

    public function test_date_range_filters_are_inclusive(): void
    {
        $this->as($this->user());
        AuditEvent::create(['tenant_id' => $this->tenant->id, 'action' => 'old_thing', 'created_at' => '2026-01-10 12:00:00']);
        AuditEvent::create(['tenant_id' => $this->tenant->id, 'action' => 'new_thing', 'created_at' => '2026-02-20 23:30:00']);

        $this->assertSame(['old_thing'], collect($this->getJson('/api/v1/audit-events?from=2026-01-10&to=2026-01-10&q=_thing')->json('data'))->pluck('action')->all());
        $this->assertSame(['new_thing'], collect($this->getJson('/api/v1/audit-events?to=2026-02-20&from=2026-02-01&q=_thing')->json('data'))->pluck('action')->all());
        $this->assertSame(2, $this->getJson('/api/v1/audit-events?from=2026-01-01&to=2026-12-31&q=_thing')->json('total'));
    }

    public function test_requires_global_scope_and_permission_and_isolates_tenants(): void
    {
        $other = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        AuditEvent::create(['tenant_id' => $other->id, 'action' => 'secreto_ajeno', 'created_at' => now()]);
        $foreign = AuditEvent::where('action', 'secreto_ajeno')->value('id');

        $this->as($this->user());
        $this->assertSame(0, $this->getJson('/api/v1/audit-events?q=secreto_ajeno')->json('total'));
        $this->getJson("/api/v1/audit-events/{$foreign}")->assertStatus(404);

        $this->as($this->user('colaborador'))->getJson('/api/v1/audit-events')->assertStatus(403);
        $this->as($this->user('admin', 'organization', (string) Str::uuid()))->getJson('/api/v1/audit-events')->assertStatus(403);
        $this->as($this->user('admin', 'organization', (string) Str::uuid()))->get('/api/v1/audit-events/export')->assertStatus(403);
    }

    public function test_export_streams_csv_and_is_itself_audited(): void
    {
        $admin = $this->user();
        $this->as($admin);
        AuditEvent::create(['tenant_id' => $this->tenant->id, 'user_id' => $admin->id, 'action' => 'created', 'entity_type' => 'App\\Models\\Person', 'new_values' => ['first_name' => 'Ana'], 'created_at' => now()]);

        $res = $this->get('/api/v1/audit-events/export?action=created');
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString(';Usuario;Acción;', $csv);
        $this->assertStringContainsString($admin->email, $csv);
        $this->assertStringContainsString('Ana', $csv);

        $event = DB::table('audit.audit_events')->where('action', 'audit_exported')->first();
        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->user_id);
        $this->assertSame('created', json_decode($event->new_values)->filters->action);
    }
}
