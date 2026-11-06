<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mail\MailService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** «Olvidé mi contraseña» y activación de cuentas invitadas. El token se guarda con hash y es de un solo uso. */
class PasswordResetController extends Controller
{
    public const RESET_MINUTES = 60;
    public const INVITE_DAYS = 7;

    public function __construct(private MailService $mail, private SettingsService $settings) {}

    /** Siempre responde igual: no revela si la cuenta existe. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant' => ['required', 'string'], 'email' => ['required', 'email']]);
        $tenant = Tenant::where('slug', $data['tenant'])->where('status', 'active')->first();
        $user = $tenant ? User::where('tenant_id', $tenant->id)->where('email', $data['email'])->where('status', 'active')->first() : null;
        if ($user) {
            self::issue($this->mail, $user, 'reset');
            AuditEvent::record('password_reset_requested', $user, null, null, $user->tenant_id, $user->id);
        }

        return response()->json(['message' => 'Si la cuenta existe, enviamos un correo con las instrucciones.'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $base = $request->validate(['tenant' => ['required', 'string'], 'email' => ['required', 'email'], 'token' => ['required', 'string']]);
        $tenant = Tenant::where('slug', $base['tenant'])->where('status', 'active')->first();
        $user = $tenant ? User::where('tenant_id', $tenant->id)->where('email', $base['email'])->first() : null;
        $row = $user ? DB::table('security.password_resets')->where('user_id', $user->id)->first() : null;
        $invalid = response()->json(['message' => 'El enlace no es válido o ya venció. Solicite uno nuevo.'], 422);
        if (! $row || ! hash_equals($row->token_hash, hash('sha256', $base['token'])) || now()->greaterThan($row->expires_at) || $user->status !== 'active') {
            return $invalid;
        }

        $request->validate(['password' => ['required', 'confirmed', $this->settings->passwordRule($user->tenant_id, $user->organization_id)]]);
        $user->forceFill(['password' => $request->input('password')])->save();
        DB::table('security.password_resets')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();   // cierra las sesiones abiertas
        AuditEvent::record('password_reset_completed', $user, null, null, $user->tenant_id, $user->id);

        return response()->json(['message' => 'Contraseña actualizada. Ya puede iniciar sesión.']);
    }

    /** Crea un token nuevo (reemplaza al anterior) y envía el correo. */
    public static function issue(MailService $mail, User $user, string $purpose): void
    {
        $token = Str::random(48);
        $expires = $purpose === 'invitation' ? now()->addDays(self::INVITE_DAYS) : now()->addMinutes(self::RESET_MINUTES);
        DB::table('security.password_resets')->updateOrInsert(['user_id' => $user->id], ['token_hash' => hash('sha256', $token), 'purpose' => $purpose, 'expires_at' => $expires, 'created_at' => now()]);
        $slug = Tenant::whereKey($user->tenant_id)->value('slug');
        $url = MailService::link('reset?'.http_build_query(['tenant' => $slug, 'email' => $user->email, 'token' => $token]));
        $mail->sendToUser($user, $purpose === 'invitation' ? 'invitation' : 'password_reset', ['url' => $url, 'minutes' => self::RESET_MINUTES, 'days' => self::INVITE_DAYS]);
    }
}
