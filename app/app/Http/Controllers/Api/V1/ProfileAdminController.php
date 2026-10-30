<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Profile;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Perfiles propios del tenant (los de sistema son de solo lectura).
 * Anti-escalamiento: solo se pueden incluir permisos que quien edita ya posee.
 */
class ProfileAdminController extends Controller
{
    public function __construct(private AccessService $access) {}

    /** Catálogo de permisos asignables. */
    public function permissions(): JsonResponse
    {
        return response()->json(DB::table('security.permissions')->orderBy('code')->pluck('code'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorizePermissions($request, $data['permissions']);

        $code = $this->uniqueCode($request->user()->tenant_id, $data['name']);
        $profile = Profile::create(['tenant_id' => $request->user()->tenant_id, 'code' => $code, 'name' => $data['name'], 'is_system' => false]);
        $this->syncPermissions($profile, $data['permissions']);
        AuditEvent::record('profile_created', null, null, ['profile' => $code, 'name' => $data['name'], 'permissions' => $data['permissions']]);

        return response()->json($this->present($profile), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $profile = $this->own($request, $id);
        $data = $this->validated($request, $profile->id);
        $this->authorizePermissions($request, $data['permissions']);

        $before = $this->currentPermissions($profile->id);
        $profile->update(['name' => $data['name']]);
        $this->syncPermissions($profile, $data['permissions']);
        AuditEvent::record('profile_updated', null, null, ['profile' => $profile->code, 'permissions_before' => $before, 'permissions_after' => $data['permissions']]);

        return response()->json($this->present($profile));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $profile = $this->own($request, $id);
        abort_if(DB::table('security.user_profiles')->where('profile_id', $profile->id)->exists(), 409, 'El perfil está asignado a usuarios. Quítelo de ellos antes de eliminarlo.');

        $profile->delete();
        AuditEvent::record('profile_deleted', null, ['profile' => $profile->code], null);

        return response()->json(null, 204);
    }

    private function own(Request $request, string $id): Profile
    {
        $profile = Profile::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        abort_if($profile->is_system, 403, 'Los perfiles del sistema no se pueden modificar.');

        return $profile;
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique(config('database.default').'.security.profiles', 'name')->where('tenant_id', $request->user()->tenant_id)->ignore($ignoreId)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists(config('database.default').'.security.permissions', 'code')],
        ]);
    }

    private function authorizePermissions(Request $request, array $permissions): void
    {
        foreach ($permissions as $p) {
            abort_unless($this->access->can($request->user(), $p, $request->header('X-Profile')), 403, "No puede incluir el permiso «{$p}» porque usted no lo posee.");
        }
    }

    private function uniqueCode(string $tenantId, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'perfil';
        $code = $base;
        for ($i = 2; DB::table('security.profiles')->where('code', $code)->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        return $code;
    }

    private function syncPermissions(Profile $profile, array $codes): void
    {
        $ids = DB::table('security.permissions')->whereIn('code', $codes)->pluck('id');
        DB::table('security.profile_permissions')->where('profile_id', $profile->id)->delete();
        DB::table('security.profile_permissions')->insert($ids->map(fn ($pid) => ['profile_id' => $profile->id, 'permission_id' => $pid])->all());
    }

    private function currentPermissions(string $profileId): array
    {
        return DB::table('security.profile_permissions as pp')->join('security.permissions as pm', 'pm.id', '=', 'pp.permission_id')
            ->where('pp.profile_id', $profileId)->orderBy('pm.code')->pluck('pm.code')->all();
    }

    private function present(Profile $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_system' => false, 'permissions' => $this->currentPermissions($p->id)];
    }
}
