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

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $a;
    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        $this->a = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'A']);
        $this->b = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'B']);
    }

    private function user(string $profile = 'admin', string $scope = 'global', ?string $scopeId = null, ?Organization $org = null): User
    {
        $u = User::create(['tenant_id' => $this->tenant->id, 'organization_id' => $org?->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
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

    private function def(array $json, string $key): array
    {
        return collect($json['definitions'])->firstWhere('key', $key);
    }

    private function png(): UploadedFile
    {
        $im = imagecreatetruecolor(1200, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagepng($im);

        return UploadedFile::fake()->createWithContent('logo.png', ob_get_clean());
    }

    public function test_defaults_tenant_override_and_organization_override_resolve_in_order(): void
    {
        $this->as($this->user());
        $this->assertSame([null, '#1f5eff', 10], [$this->def($this->getJson('/api/v1/settings')->json(), 'brand.name')['value'], $this->def($this->getJson('/api/v1/settings')->json(), 'brand.primary_color')['inherited'], $this->def($this->getJson('/api/v1/settings')->json(), 'security.password_min_length')['inherited']]);

        $this->putJson('/api/v1/settings', ['values' => ['brand.primary_color' => '#0E9F6E', 'locale.timezone' => 'America/Punta_Arenas', 'brand.name' => 'Grupo Demo']])->assertOk();
        $this->putJson('/api/v1/settings', ['organization_id' => $this->a->id, 'values' => ['brand.primary_color' => '#dc2626']])->assertOk();

        $orgView = $this->getJson('/api/v1/settings?organization_id='.$this->a->id)->json();
        $this->assertSame(['#dc2626', '#0e9f6e'], [$this->def($orgView, 'brand.primary_color')['value'], $this->def($orgView, 'brand.primary_color')['inherited']]);   // propio e heredado del tenant
        $this->assertSame([null, 'America/Punta_Arenas'], [$this->def($orgView, 'locale.timezone')['value'], $this->def($orgView, 'locale.timezone')['inherited']]);

        // lo que ve cada usuario en /me: la empresa A usa su color; la B hereda el del tenant
        $this->as($this->user('colaborador', 'organization', $this->a->id, $this->a));
        $this->assertSame(['#dc2626', 'Grupo Demo', 'America/Punta_Arenas'], [$this->getJson('/api/v1/me')->json('settings.brand.primary_color'), $this->getJson('/api/v1/me')->json('settings.brand.name'), $this->getJson('/api/v1/me')->json('settings.locale.timezone')]);
        $this->as($this->user('colaborador', 'organization', $this->b->id, $this->b));
        $this->assertSame('#0e9f6e', $this->getJson('/api/v1/me')->json('settings.brand.primary_color'));

        // null elimina el valor propio y vuelve a heredar
        $this->as($this->user())->putJson('/api/v1/settings', ['organization_id' => $this->a->id, 'values' => ['brand.primary_color' => null]])->assertOk();
        $this->assertNull($this->def($this->getJson('/api/v1/settings?organization_id='.$this->a->id)->json(), 'brand.primary_color')['value']);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'settings_changed']);
    }

    public function test_values_are_validated(): void
    {
        $this->as($this->user());
        foreach ([['brand.primary_color', 'rojo'], ['locale.timezone', 'Marte/Olimpo'], ['locale.date_format', 'dd-mm'], ['security.password_min_length', 3],
            ['security.password_min_length', 500], ['security.mfa_required', 'quizás'], ['brand.name', str_repeat('x', 81)], ['clave.inventada', 1]] as [$k, $v]) {
            $this->putJson('/api/v1/settings', ['values' => [$k => $v]])->assertStatus(422);
        }
        $this->putJson('/api/v1/settings', ['values' => ['security.password_min_length' => '12', 'security.password_require_symbol' => true]])->assertOk();
        $this->assertSame(12, $this->def($this->getJson('/api/v1/settings')->json(), 'security.password_min_length')['value']);
        $this->putJson('/api/v1/settings', ['organization_id' => (string) Str::uuid(), 'values' => ['brand.name' => 'x']])->assertStatus(404);
    }

    public function test_permissions_and_scope(): void
    {
        $this->as($this->user('colaborador'))->getJson('/api/v1/settings')->assertStatus(403);

        $scoped = $this->user('admin', 'organization', $this->a->id);
        $this->as($scoped);
        $this->getJson('/api/v1/settings')->assertOk();                                                                       // puede ver el nivel general
        $this->putJson('/api/v1/settings', ['values' => ['brand.name' => 'x']])->assertStatus(403);                           // pero no editarlo
        $this->putJson('/api/v1/settings', ['organization_id' => $this->a->id, 'values' => ['brand.name' => 'Mi empresa']])->assertOk();
        $this->putJson('/api/v1/settings', ['organization_id' => $this->b->id, 'values' => ['brand.name' => 'Ajena']])->assertStatus(403);
        $this->getJson('/api/v1/settings?organization_id='.$this->b->id)->assertStatus(403);
    }

    public function test_password_policy_is_enforced_per_organization(): void
    {
        $this->as($this->user());
        $this->putJson('/api/v1/settings', ['organization_id' => $this->a->id, 'values' => ['security.password_min_length' => 16, 'security.password_require_symbol' => true, 'security.password_require_mixed_case' => true]])->assertOk();

        // en la empresa A la política es estricta; en la B rige la general
        $this->postJson('/api/v1/users', ['email' => 'a1@t.cl', 'password' => 'Clave-segura-1', 'organization_id' => $this->a->id])->assertStatus(422);
        $this->postJson('/api/v1/users', ['email' => 'a2@t.cl', 'password' => 'clave-muy-larga-123!', 'organization_id' => $this->a->id])->assertStatus(422);   // sin mayúscula
        $this->postJson('/api/v1/users', ['email' => 'a3@t.cl', 'password' => 'Clave-Muy-Larga-123!', 'organization_id' => $this->a->id])->assertCreated();
        $this->postJson('/api/v1/users', ['email' => 'b1@t.cl', 'password' => 'Clave-segura-1', 'organization_id' => $this->b->id])->assertCreated();

        // y al cambiar su propia clave
        $ana = User::where('email', 'a3@t.cl')->first();
        $this->as($ana)->postJson('/api/v1/me/password', ['current_password' => 'Clave-Muy-Larga-123!', 'password' => 'Corta-1-Aa', 'password_confirmation' => 'Corta-1-Aa'])->assertStatus(422);
    }

    public function test_logo_upload_is_processed_served_by_signed_url_and_inherited(): void
    {
        $this->as($this->user());
        $res = $this->post('/api/v1/settings/logo', ['file' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $url = $res->json('logo.url');
        $this->assertNotNull($url);

        $img = $this->get($url)->assertOk();
        $this->assertSame('image/webp', $img->headers->get('Content-Type'));
        $info = getimagesizefromstring($img->streamedContent() ?: Storage::disk('local')->get(DB::table('core.settings')->where('key', 'brand.logo')->value('value') ? json_decode(DB::table('core.settings')->where('key', 'brand.logo')->value('value'))->path : ''));
        $this->assertSame([512, 256], [$info[0], $info[1]]);                       // reducido a 512 px conservando la proporción
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=00', $url))->assertStatus(403);

        // la empresa A hereda el logo del tenant; luego sube el suyo (SVG)
        $this->as($this->user('colaborador', 'organization', $this->a->id, $this->a));
        $this->assertNotNull($this->getJson('/api/v1/me')->json('settings.brand.logo_url'));

        $this->as($this->user());
        $svg = UploadedFile::fake()->createWithContent('marca.svg', "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'><rect width='10' height='10'/></svg>");
        $own = $this->post('/api/v1/settings/logo', ['file' => $svg, 'organization_id' => $this->a->id], ['Accept' => 'application/json'])->assertOk()->json('logo.url');
        $r = $this->get($own)->assertOk();
        $this->assertSame('image/svg+xml', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', $r->headers->get('Content-Security-Policy'));
        $this->assertNotSame($url, $own);

        $this->post('/api/v1/settings/logo', ['file' => UploadedFile::fake()->createWithContent('x.png', 'no es imagen')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->deleteJson('/api/v1/settings/logo?organization_id='.$this->a->id)->assertNoContent();
        $this->assertSame($url, $this->as($this->user('colaborador', 'organization', $this->a->id, $this->a))->getJson('/api/v1/me')->json('settings.brand.logo_url'));   // vuelve a heredar
    }
}
