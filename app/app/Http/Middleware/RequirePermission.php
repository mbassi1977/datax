<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('permission:organizaciones.ver'). El perfil activo viaja en el header X-Profile. */
class RequirePermission
{
    public function __construct(private AccessService $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $profile = $request->header('X-Profile');

        if (! $user || ($profile && ! $user->is_superadmin && ! $this->access->holdsProfile($user, $profile))) {
            abort(403, 'Perfil no asignado al usuario.');
        }
        if (! $this->access->can($user, $permission, $profile)) {
            abort(403, 'Sin permiso: '.$permission);
        }

        return $next($request);
    }
}
