<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mfa\Totp;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
    }

    private function user(string $profile = 'admin', ?Organization $org = null): User
    {
        $u = User::create(['tenant_id' => $this->tenant->id, 'organization_id' => $org?->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $u->id, 'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => 'global', 'scope_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function nowCode(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::step() + $offset);
    }

    /** Activa MFA de punta a punta y devuelve [secreto, códigos de recuperación]. */
    private function enroll(User $u): array
    {
        $this->as($u);
        $secret = $this->postJson('/api/v1/me/mfa/setup')->assertOk()->json('secret');
        $codes = $this->postJson('/api/v1/me/mfa/confirm', ['code' => $this->nowCode($secret)])->assertOk()->json('recovery_codes');

        return [$secret, $codes];
    }

    public function test_totp_matches_the_rfc_6238_vectors_and_rejects_bad_input(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $t => $code) {
            $this->assertSame($code, Totp::code($secret, Totp::step($t)));
            $this->assertSame(Totp::step($t), Totp::verify($secret, $code, $t));
        }
        $this->assertNotNull(Totp::verify($secret, '287 082', 59));            // tolera espacios
        $this->assertNotNull(Totp::verify($secret, '287082', 59 + 30));        // ±1 intervalo
        $this->assertNull(Totp::verify($secret, '287082', 59 + 90));           // fuera de ventana
        foreach (['', 'abcdef', '12345', '1234567'] as $bad) {
            $this->assertNull(Totp::verify($secret, $bad, 59));
        }
        $this->assertStringStartsWith('otpauth://totp/Acme%3Aana%40t.cl?', Totp::uri($secret, 'ana@t.cl', 'Acme'));
    }

    public function test_enrollment_requires_a_valid_code_and_stores_secret_encrypted_and_recovery_hashed(): void
    {
        $u = $this->user();
        $this->as($u);
        $setup = $this->postJson('/api/v1/me/mfa/setup')->assertOk();
        $this->assertStringContainsString($setup->json('secret'), $setup->json('otpauth_uri'));
        $this->assertFalse($u->fresh()->mfa_enabled);                                   // pendiente

        $this->postJson('/api/v1/me/mfa/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->postJson('/api/v1/me/mfa/confirm', ['code' => $this->nowCode($setup->json('secret'))])->assertOk()->json('recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $codes[0]);

        $raw = DB::table('security.users')->where('id', $u->id)->first();
        $this->assertNotSame($setup->json('secret'), $raw->mfa_secret);                 // cifrado
        $this->assertSame($setup->json('secret'), Crypt::decryptString($raw->mfa_secret));
        $this->assertStringNotContainsString($codes[0], $raw->mfa_recovery_codes);       // solo hashes

        $this->postJson('/api/v1/me/mfa/setup')->assertStatus(409);                      // ya activa
        $me = $this->getJson('/api/v1/me')->json();
        $this->assertSame([true, 10], [$me['mfa']['enabled'], $me['mfa']['recovery_remaining']]);
        $this->assertArrayNotHasKey('mfa_secret', $this->getJson('/api/v1/users')->json('data.0'));   // jamás se expone
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'mfa_enabled', 'user_id' => $u->id]);
    }

    public function test_login_with_mfa_needs_the_second_step_and_codes_cannot_be_replayed(): void
    {
        $u = $this->user();
        [$secret] = $this->enroll($u);
        $this->app['auth']->forgetGuards();

        $first = $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => $u->email, 'password' => 'Secreto-12345'])->assertOk();
        $this->assertTrue($first->json('mfa_required'));
        $this->assertNull($first->json('token'));                                       // la contraseña sola no entrega token

        $challenge = $first->json('mfa_token');
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'code' => '111111'])->assertStatus(401);
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge])->assertStatus(422);
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => 'inventado', 'code' => $this->nowCode($secret, 1)])->assertStatus(401);

        // el código usado al activar (intervalo actual) no sirve de nuevo; uno posterior sí
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'code' => $this->nowCode($secret)])->assertStatus(401);
        $ok = $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'code' => $this->nowCode($secret, 1)])->assertOk();
        $this->assertNotNull($ok->json('token'));
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'code' => $this->nowCode($secret, 1)])->assertStatus(401);   // repetido

        $this->assertDatabaseHas('audit.audit_events', ['action' => 'mfa_failed', 'user_id' => $u->id]);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'login', 'user_id' => $u->id]);
    }

    public function test_recovery_codes_work_once_and_attempts_are_limited(): void
    {
        $u = $this->user();
        [, $codes] = $this->enroll($u);
        $this->app['auth']->forgetGuards();
        RateLimiter::clear("mfa:{$u->id}");

        $challenge = $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => $u->email, 'password' => 'Secreto-12345'])->json('mfa_token');
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'recovery_code' => $codes[0]])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'recovery_code' => $codes[0]])->assertStatus(401);   // ya gastado
        $this->assertSame(9, count($u->fresh()->mfa_recovery_codes));

        // 5 fallos bloquean incluso un código correcto
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'code' => '000000'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'recovery_code' => $codes[1]])->assertStatus(429);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'mfa_locked', 'user_id' => $u->id]);
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $u = $this->user();
        [$secret] = $this->enroll($u);
        $this->app['auth']->forgetGuards();
        $challenge = $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => $u->email, 'password' => 'Secreto-12345'])->json('mfa_token');

        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/mfa', ['mfa_token' => $challenge, 'code' => $this->nowCode($secret, 1)])->assertStatus(401);
    }

    public function test_disable_and_regenerate_require_password_and_code_and_respect_policy(): void
    {
        $u = $this->user();
        [$secret] = $this->enroll($u);
        $codeNow = fn () => $this->nowCode($secret, 1);                                  // intervalo siguiente: dentro de la ventana y aún sin usar

        $this->deleteJson('/api/v1/me/mfa', ['password' => 'mala', 'code' => $codeNow()])->assertStatus(422);
        $this->deleteJson('/api/v1/me/mfa', ['password' => 'Secreto-12345', 'code' => '000000'])->assertStatus(422);
        $new = $this->postJson('/api/v1/me/mfa/recovery-codes', ['password' => 'Secreto-12345', 'code' => $codeNow()])->assertOk()->json('recovery_codes');
        $this->assertCount(10, $new);

        // si la empresa exige MFA no se puede desactivar
        $this->putJson('/api/v1/settings', ['values' => ['security.mfa_required' => true]])->assertOk();
        $this->deleteJson('/api/v1/me/mfa', ['password' => 'Secreto-12345', 'recovery_code' => $new[0]])->assertStatus(403);
        $this->putJson('/api/v1/settings', ['values' => ['security.mfa_required' => false]])->assertOk();

        $this->deleteJson('/api/v1/me/mfa', ['password' => 'Secreto-12345', 'recovery_code' => $new[1]])->assertNoContent();
        $this->assertNull(DB::table('security.users')->where('id', $u->id)->value('mfa_secret'));
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'mfa_disabled', 'user_id' => $u->id]);
    }

    public function test_company_policy_blocks_everything_until_the_user_enrolls(): void
    {
        $admin = $this->user();
        $org = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Exigente']);
        $this->as($admin)->putJson('/api/v1/settings', ['organization_id' => $org->id, 'values' => ['security.mfa_required' => true]])->assertOk();

        $worker = $this->user('admin', $org);
        $this->as($worker);
        $blocked = $this->getJson('/api/v1/users')->assertStatus(403);
        $this->assertSame('mfa_enrollment_required', $blocked->json('code'));
        $this->getJson('/api/v1/organizations')->assertStatus(403);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('settings.security.mfa_required', true);   // la interfaz puede pedir el alta
        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->enroll($worker);
        $this->as($worker)->getJson('/api/v1/users')->assertOk();                         // ya enrolada: acceso normal
        $this->as($admin)->getJson('/api/v1/users')->assertOk();                          // quienes no están en esa empresa no se ven afectados
    }

    public function test_admin_can_reset_a_users_mfa_within_scope_and_it_is_audited(): void
    {
        $victim = $this->user('colaborador');
        $this->enroll($victim);
        $token = $victim->createToken('t')->plainTextToken;

        $admin = $this->user();
        $this->as($admin)->deleteJson("/api/v1/users/{$victim->id}/mfa")->assertNoContent();
        $this->assertFalse($victim->fresh()->mfa_enabled);
        $this->assertSame(0, $victim->tokens()->count());
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'mfa_reset', 'entity_id' => $victim->id]);
        $this->assertNotEmpty($token);

        $this->as($this->user('colaborador'))->deleteJson("/api/v1/users/{$victim->id}/mfa")->assertStatus(403);   // sin usuarios.editar
    }
}
