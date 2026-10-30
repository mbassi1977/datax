<?php

namespace App\Http\Middleware;

use App\Support\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Si la política de la empresa exige MFA, quien no la tiene solo puede consultar /me, activarla o cerrar sesión. */
class EnsureMfaEnrolled
{
    public function __construct(private SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->mfa_enabled_at === null
            && $this->settings->effective($user->tenant_id, $user->organization_id)['security.mfa_required']
            && ! $request->is('api/v1/me', 'api/v1/me/mfa/*', 'api/v1/auth/logout')) {
            return response()->json(['message' => 'Su empresa exige activar la verificación en dos pasos antes de continuar.', 'code' => 'mfa_enrollment_required'], 403);
        }

        return $next($request);
    }
}
