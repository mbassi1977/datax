<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Access\AccessService;
use App\Support\Mfa\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request, MfaService $mfa): JsonResponse
    {
        $data = $request->validate([
            'tenant' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = Tenant::where('slug', $data['tenant'])->where('status', 'active')->first();
        $user = $tenant ? User::where('tenant_id', $tenant->id)->where('email', $data['email'])->first() : null;

        if (! $user || $user->status !== 'active' || ! Hash::check($data['password'], $user->password)) {
            AuditEvent::record('login_failed', null, null, ['email' => $data['email'], 'tenant' => $data['tenant']], $tenant?->id, $user?->id);

            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        // Con MFA activa, la contraseña sola no basta: se entrega un desafío de 5 minutos para el segundo paso.
        if ($mfa->isEnabled($user)) {
            return response()->json(['mfa_required' => true, 'mfa_token' => $mfa->challengeToken($user)]);
        }

        return $this->issueToken($user, false);
    }

    /** Segundo paso del login: código de la app autenticadora o código de recuperación. */
    public function verifyMfa(Request $request, MfaService $mfa): JsonResponse
    {
        $data = $request->validate(['mfa_token' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:20']]);
        $user = $mfa->userFromChallenge($data['mfa_token']);
        if (! $user || $user->status !== 'active') {
            return response()->json(['message' => 'La verificación venció. Inicie sesión nuevamente.'], 401);
        }
        if (empty($data['code']) && empty($data['recovery_code'])) {
            return response()->json(['message' => 'Ingrese el código de su aplicación autenticadora.'], 422);
        }
        if ($mfa->tooManyAttempts($user)) {
            AuditEvent::record('mfa_locked', null, null, null, $user->tenant_id, $user->id);

            return response()->json(['message' => 'Demasiados intentos. Espere unos minutos e inicie sesión nuevamente.'], 429);
        }
        if (! $mfa->check($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            $mfa->hit($user);
            AuditEvent::record('mfa_failed', null, null, null, $user->tenant_id, $user->id);

            return response()->json(['message' => 'Código incorrecto.'], 401);
        }
        $mfa->clear($user);

        return $this->issueToken($user, true);
    }

    private function issueToken(User $user, bool $withMfa): JsonResponse
    {
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditEvent::record('login', null, null, $withMfa ? ['mfa' => true] : null, $user->tenant_id, $user->id);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => ['id' => $user->id, 'email' => $user->email],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        AuditEvent::record('logout');

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', app(\App\Support\Settings\SettingsService::class)->passwordRule($request->user()->tenant_id, $request->user()->organization_id), 'different:current_password'],
        ]);
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'La clave actual no coincide.'], 422);
        }

        $user->update(['password' => $data['password']]);
        $user->tokens()->when($user->currentAccessToken(), fn ($q, $t) => $q->where('id', '!=', $t->id))->delete(); // cierra las demás sesiones
        AuditEvent::record('password_changed');

        return response()->json(['message' => 'Clave actualizada.']);
    }

    public function me(Request $request, AccessService $access): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'avatar_url' => $user->avatar_url,
            'settings' => app(\App\Support\Settings\SettingsService::class)->forClient($user->tenant_id, $user->organization_id),
            'mfa' => ['enabled' => $user->mfa_enabled_at !== null, 'recovery_remaining' => count($user->mfa_recovery_codes ?? [])],
            'is_superadmin' => $user->is_superadmin,
            'profiles' => DB::table('security.user_profiles as up')
                ->join('security.profiles as p', 'p.id', '=', 'up.profile_id')
                ->where('up.user_id', $user->id)
                ->get(['p.code', 'p.name', 'up.scope_type', 'up.scope_id', 'up.valid_until']),
            'permissions' => $access->permissions($user, $request->header('X-Profile')),
        ]);
    }
}
