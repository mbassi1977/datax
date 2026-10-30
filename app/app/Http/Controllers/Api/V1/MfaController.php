<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Mfa\MfaService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Alta, baja y códigos de recuperación de la verificación en dos pasos del propio usuario. */
class MfaController extends Controller
{
    public function __construct(private MfaService $mfa, private SettingsService $settings) {}

    public function setup(Request $request): JsonResponse
    {
        abort_if($this->mfa->isEnabled($request->user()), 409, 'La verificación en dos pasos ya está activa.');

        return response()->json($this->mfa->begin($request->user()));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        $this->throttle($user);

        $codes = $this->mfa->confirm($user, $data['code']);
        if ($codes === null) {
            $this->mfa->hit($user);
            abort(422, 'Código incorrecto. Revise que la hora de su teléfono sea automática e inténtelo de nuevo.');
        }
        $this->mfa->clear($user);
        AuditEvent::record('mfa_enabled');

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Desactivar exige la contraseña y un código vigente; no se permite si la política de la empresa la exige. */
    public function disable(Request $request): JsonResponse
    {
        $user = $this->reauthenticate($request);
        $required = $this->settings->effective($user->tenant_id, $user->organization_id)['security.mfa_required'];
        abort_if($required, 403, 'La política de su empresa exige la verificación en dos pasos; no se puede desactivar.');

        $this->mfa->disable($user);
        AuditEvent::record('mfa_disabled');

        return response()->json(null, 204);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->reauthenticate($request);
        AuditEvent::record('mfa_recovery_regenerated');

        return response()->json(['recovery_codes' => $this->mfa->regenerateRecoveryCodes($user)]);
    }

    private function reauthenticate(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:20']]);
        $user = $request->user();
        abort_unless($this->mfa->isEnabled($user), 409, 'La verificación en dos pasos no está activa.');
        $this->throttle($user);

        if (! Hash::check($data['password'], $user->password) || ! $this->mfa->check($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            $this->mfa->hit($user);
            abort(422, 'Contraseña o código incorrectos.');
        }
        $this->mfa->clear($user);

        return $user;
    }

    private function throttle($user): void
    {
        abort_if(RateLimiter::tooManyAttempts("mfa:{$user->id}", MfaService::MAX_ATTEMPTS), 429, 'Demasiados intentos. Espere unos minutos.');
    }
}
