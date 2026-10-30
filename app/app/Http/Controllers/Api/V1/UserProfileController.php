<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Catálogo de perfiles y asignación de perfiles (con alcance y vigencia) a usuarios. */
class UserProfileController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function profiles(Request $request): JsonResponse
    {
        $profiles = DB::table('security.profiles')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $request->user()->tenant_id))
            ->orderBy('name')->get(['id', 'code', 'name', 'is_system']);
        $counts = DB::table('security.user_profiles')->selectRaw('profile_id, count(*) as n')->groupBy('profile_id')->pluck('n', 'profile_id');

        $perms = DB::table('security.profile_permissions as pp')
            ->join('security.permissions as pm', 'pm.id', '=', 'pp.permission_id')
            ->whereIn('pp.profile_id', $profiles->pluck('id'))
            ->get(['pp.profile_id', 'pm.code'])->groupBy('profile_id');

        return response()->json($profiles->map(fn ($p) => (array) $p + ['users_count' => (int) ($counts[$p->id] ?? 0), 'permissions' => ($perms[$p->id] ?? collect())->pluck('code')->sort()->values()]));
    }

    public function index(Request $request, string $userId): JsonResponse
    {
        $user = $this->targetUser($request, $userId, 'usuarios.ver');

        return response()->json(DB::table('security.user_profiles as up')
            ->join('security.profiles as p', 'p.id', '=', 'up.profile_id')
            ->where('up.user_id', $user->id)
            ->get(['up.id', 'p.code', 'p.name', 'up.scope_type', 'up.scope_id', 'up.valid_from', 'up.valid_until']));
    }

    public function assign(Request $request, string $userId): JsonResponse
    {
        $grantor = $request->user();
        $user = $this->targetUser($request, $userId, 'usuarios.editar');

        $data = $request->validate([
            'profile' => ['required', 'string'],
            'scope_type' => ['required', Rule::in(['global', 'holding', 'organization', 'branch', 'area', 'project'])],
            'scope_id' => ['nullable', 'uuid', 'required_unless:scope_type,global'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $profile = DB::table('security.profiles')->where('code', $data['profile'])
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $grantor->tenant_id))->first();
        if (! $profile) {
            abort(422, 'Perfil inexistente.');
        }
        if ($profile->code === 'superadmin' && ! $grantor->is_superadmin) {
            abort(403, 'Solo un superadministrador puede asignar ese perfil.');
        }

        $scopeId = $data['scope_type'] === 'global' ? null : $data['scope_id'];
        if ($scopeId && $this->access->organizationsOfScope($grantor->tenant_id, $data['scope_type'], $scopeId) === []) {
            abort(422, 'El alcance indicado no existe en este tenant.');
        }
        if (! $this->access->canGrant($grantor, $profile->id, $data['scope_type'], $scopeId, $request->header('X-Profile'))) {
            abort(403, 'No puede asignar un perfil con más permisos o alcance que los suyos.');
        }

        $id = (string) Str::uuid();
        DB::table('security.user_profiles')->insert([
            'id' => $id, 'user_id' => $user->id, 'profile_id' => $profile->id,
            'scope_type' => $data['scope_type'], 'scope_id' => $scopeId,
            'valid_from' => $data['valid_from'] ?? null, 'valid_until' => $data['valid_until'] ?? null,
            'delegated_by' => $grantor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditEvent::record('profile_assigned', $user, null, ['profile' => $profile->code] + $data);

        return response()->json(['id' => $id], 201);
    }

    public function revoke(Request $request, string $userId, string $assignmentId): JsonResponse
    {
        $user = $this->targetUser($request, $userId, 'usuarios.editar');
        $row = DB::table('security.user_profiles')->where('id', $assignmentId)->where('user_id', $user->id)->first();
        abort_unless($row, 404);

        if (! $this->access->canGrant($request->user(), $row->profile_id, $row->scope_type, $row->scope_id, $request->header('X-Profile'))) {
            abort(403, 'No puede revocar una asignación que excede su propio alcance.');
        }
        DB::table('security.user_profiles')->where('id', $assignmentId)->delete();
        AuditEvent::record('profile_revoked', $user, ['assignment' => (array) $row], null);

        return response()->json(null, 204);
    }

    /** El administrador restablece la MFA de un usuario que perdió su dispositivo y sus códigos de recuperación. */
    public function resetMfa(Request $request, string $userId, \App\Support\Mfa\MfaService $mfa): JsonResponse
    {
        $user = $this->targetUser($request, $userId, 'usuarios.editar');
        $mfa->disable($user);
        $user->tokens()->delete(); // sus sesiones abiertas se cierran
        AuditEvent::record('mfa_reset', $user);

        return response()->json(null, 204);
    }

    private function targetUser(Request $request, string $userId, string $permission): User
    {
        $user = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($userId);
        $allowed = $this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile'));
        if ($allowed !== null && ($user->organization_id === null || ! in_array($user->organization_id, $allowed, true))) {
            abort(403, 'Fuera de su alcance.');
        }

        return $user;
    }
}
