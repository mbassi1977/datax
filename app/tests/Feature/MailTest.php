<?php

namespace Tests\Feature;

use App\Mail\TransactionalMail;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hrx.mail_queue' => true, 'queue.default' => 'sync']);   // sin worker: la cola sincrónica ejecuta el envío
        Mail::fake();
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Empresa A']);
    }

    private function user(string $profile, string $email = null): User
    {
        $u = User::create(['tenant_id' => $this->tenant->id, 'email' => $email ?? Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        DB::table('security.user_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'), 'scope_type' => 'global', 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    private function tokenFromMail(): string
    {
        $url = null;
        Mail::assertSent(TransactionalMail::class, function ($m) use (&$url) { $url = $m->payload['action']['url']; return true; });
        parse_str(parse_url(str_replace('/#/', '/', $url), PHP_URL_QUERY), $q);

        return $q['token'];
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        $u = $this->user('colaborador', 'ana@t.cl');
        $this->postJson('/api/v1/auth/forgot-password', ['tenant' => 't1', 'email' => 'nadie@t.cl'])->assertStatus(202);   // no revela cuentas
        Mail::assertNothingSent();

        $this->postJson('/api/v1/auth/forgot-password', ['tenant' => 't1', 'email' => 'ana@t.cl'])->assertStatus(202);
        $token = $this->tokenFromMail();
        $this->assertDatabaseHas('audit.mail_log', ['to_email' => 'ana@t.cl', 'template' => 'password_reset', 'status' => 'sent']);
        $this->assertDatabaseMissing('security.password_resets', ['token_hash' => $token]);   // solo se guarda el hash

        $u->createToken('x');
        $body = ['tenant' => 't1', 'email' => 'ana@t.cl', 'token' => $token, 'password' => 'Nueva-clave-987', 'password_confirmation' => 'Nueva-clave-987'];
        $this->postJson('/api/v1/auth/reset-password', ['token' => 'malo'] + $body)->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['password' => 'corta', 'password_confirmation' => 'corta'] + $body)->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', $body)->assertOk();
        $this->assertSame(0, $u->tokens()->count());                                     // cierra sesiones
        $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422);        // un solo uso
        $this->postJson('/api/v1/auth/login', ['tenant' => 't1', 'email' => 'ana@t.cl', 'password' => 'Nueva-clave-987'])->assertOk();
    }

    public function test_expired_token_is_rejected(): void
    {
        $u = $this->user('colaborador', 'ana@t.cl');
        $this->postJson('/api/v1/auth/forgot-password', ['tenant' => 't1', 'email' => 'ana@t.cl']);
        $token = $this->tokenFromMail();
        DB::table('security.password_resets')->where('user_id', $u->id)->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/reset-password', ['tenant' => 't1', 'email' => 'ana@t.cl', 'token' => $token, 'password' => 'Nueva-clave-987', 'password_confirmation' => 'Nueva-clave-987'])->assertStatus(422);
    }

    public function test_admin_status_test_send_invitation_and_permissions(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('colaborador', 'nuevo@t.cl');

        $this->actingAs($this->user('colaborador'), 'sanctum');
        $this->getJson('/api/v1/mail/status')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum');
        $this->putJson('/api/v1/settings', ['values' => ['mail.from_name' => 'Soporte Acme', 'mail.reply_to' => 'ayuda@acme.cl']])->assertOk();
        $this->postJson('/api/v1/mail/test', ['to' => 'yo@acme.cl'])->assertStatus(202);
        Mail::assertSent(TransactionalMail::class, fn ($m) => $m->hasTo('yo@acme.cl') && $m->hasReplyTo('ayuda@acme.cl') && $m->fromName === 'Soporte Acme');

        $this->postJson("/api/v1/users/{$target->id}/send-access")->assertStatus(202);
        $this->assertDatabaseHas('audit.mail_log', ['to_email' => 'nuevo@t.cl', 'template' => 'invitation', 'status' => 'sent']);

        $st = $this->getJson('/api/v1/mail/status')->assertOk();
        $this->assertFalse($st->json('live'));                                           // MAIL_MAILER=log en pruebas
        $this->assertSame(2, $st->json('counts.sent'));
        $this->putJson('/api/v1/settings', ['values' => ['mail.reply_to' => 'no-es-correo']])->assertStatus(422);
    }
}
