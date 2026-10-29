<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Usuario → perfil activo → permiso → alcance (cap. 13.12).
 * Todo queda acotado al tenant del usuario.
 */
class AccessService
{
    /** @return Collection<int, object{code:string, scope_type:string, scope_id:?string}> */
    public function grants(User $user, ?string $profileCode = null): Collection
    {
        return DB::table('security.user_profiles as up')
            ->join('security.profiles as p', 'p.id', '=', 'up.profile_id')
            ->join('security.profile_permissions as pp', 'pp.profile_id', '=', 'p.id')
            ->join('security.permissions as pm', 'pm.id', '=', 'pp.permission_id')
            ->where('up.user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('up.valid_from')->orWhere('up.valid_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('up.valid_until')->orWhere('up.valid_until', '>=', today()))
            ->when($profileCode, fn ($q) => $q->where('p.code', $profileCode))
            ->select('pm.code', 'up.scope_type', 'up.scope_id')
            ->get();
    }

    public function holdsProfile(User $user, string $profileCode): bool
    {
        return DB::table('security.user_profiles as up')
            ->join('security.profiles as p', 'p.id', '=', 'up.profile_id')
            ->where('up.user_id', $user->getKey())->where('p.code', $profileCode)->exists();
    }

    public function permissions(User $user, ?string $profileCode = null): array
    {
        return $this->grants($user, $profileCode)->pluck('code')->unique()->values()->all();
    }

    public function can(User $user, string $permission, ?string $profileCode = null): bool
    {
        return $user->is_superadmin || $this->grants($user, $profileCode)->contains('code', $permission);
    }

    /**
     * Organizaciones del tenant donde el usuario tiene el permiso.
     * null = todas las del tenant (alcance global); [] = ninguna.
     *
     * @return array<int, string>|null
     */
    public function allowedOrganizationIds(User $user, string $permission, ?string $profileCode = null): ?array
    {
        if ($user->is_superadmin) {
            return null;
        }

        $ids = [];
        foreach ($this->grants($user, $profileCode)->where('code', $permission) as $g) {
            if ($g->scope_type === 'global') {
                return null;
            }
            $ids = array_merge($ids, $this->organizationsOfScope($user->tenant_id, $g->scope_type, $g->scope_id));
        }

        return array_values(array_unique($ids));
    }

    public function canOnOrganization(User $user, string $permission, string $organizationId, ?string $profileCode = null): bool
    {
        $allowed = $this->allowedOrganizationIds($user, $permission, $profileCode);

        return $allowed === null || in_array($organizationId, $allowed, true);
    }

    /**
     * Anti-escalamiento: solo se puede asignar un perfil si quien lo asigna ya tiene
     * cada uno de sus permisos, con un alcance que cubra el destino.
     */
    public function canGrant(User $grantor, string $profileId, string $scopeType, ?string $scopeId, ?string $activeProfile = null): bool
    {
        if ($grantor->is_superadmin) {
            return true;
        }

        $permissions = DB::table('security.profile_permissions as pp')
            ->join('security.permissions as pm', 'pm.id', '=', 'pp.permission_id')
            ->where('pp.profile_id', $profileId)->pluck('pm.code');

        $target = $scopeType === 'global' ? null : $this->organizationsOfScope($grantor->tenant_id, $scopeType, $scopeId);

        foreach ($permissions as $permission) {
            $allowed = $this->allowedOrganizationIds($grantor, $permission, $activeProfile);
            if ($allowed === null) {
                continue; // alcance global: cubre cualquier destino
            }
            if ($target === null || array_diff($target, $allowed) !== []) {
                return false;
            }
        }

        return true;
    }

    /** Resuelve el alcance (holding, sucursal, área, proyecto...) a las organizaciones que cubre. */
    public function organizationsOfScope(string $tenantId, string $type, ?string $scopeId): array
    {
        if ($scopeId === null) {
            return [];
        }

        [$table, $key, $column] = match ($type) {
            'organization' => ['core.organizations', 'id', 'id'],
            'holding' => ['core.organizations', 'holding_id', 'id'],
            'branch' => ['core.branches', 'id', 'organization_id'],
            'area' => ['core.areas', 'id', 'organization_id'],
            'project' => ['master.projects', 'id', 'organization_id'],
            default => [null, null, null],
        };

        if ($table === null) {
            return [];
        }

        return DB::table($table)->where('tenant_id', $tenantId)->where($key, $scopeId)->pluck($column)->all();
    }
}
