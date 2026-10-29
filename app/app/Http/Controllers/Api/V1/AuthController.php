<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
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

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditEvent::record('login', null, null, null, $user->tenant_id, $user->id);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => ['id' => $user->id, 'email' => $user->email],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        AuditEvent::record('logout');

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request, AccessService $access): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'is_superadmin' => $user->is_superadmin,
            'profiles' => DB::table('security.user_profiles as up')
                ->join('security.profiles as p', 'p.id', '=', 'up.profile_id')
                ->where('up.user_id', $user->id)
                ->get(['p.code', 'p.name', 'up.scope_type', 'up.scope_id', 'up.valid_until']),
            'permissions' => $access->permissions($user, $request->header('X-Profile')),
        ]);
    }
}
