<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Un usuario suspendido o bloqueado pierde acceso aunque conserve un token. */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->status !== 'active') {
            $request->user()->tokens()->delete();
            abort(401, 'Usuario inactivo.');
        }

        return $next($request);
    }
}
