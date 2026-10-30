<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Avatar;
use App\Models\AvatarCollection;
use App\Models\User;
use App\Support\Access\AccessService;
use App\Support\Avatars\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Administración de galerías de avatares y su asignación a empresas.
 * Las colecciones del sistema (tenant_id nulo) se pueden ver pero no editar. Escribir exige alcance global.
 */
class AvatarAdminController extends Controller
{
    public function __construct(private AccessService $access, private AvatarService $avatars) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant_id;
        $collections = AvatarCollection::where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant))
            ->with(['avatars', 'organizations:id'])->orderByRaw('tenant_id is null desc')->orderBy('name')->get();

        return response()->json($collections->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'is_global' => $c->is_global, 'is_active' => $c->is_active,
            'is_system' => $c->tenant_id === null,
            'organization_ids' => $c->organizations->pluck('id')->values(),
            'avatars' => $c->avatars->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'is_active' => $a->is_active, 'url' => $this->avatars->galleryUrl($a)])->values(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireGlobal($request, 'avatares.crear');
        $data = $this->validated($request);
        $c = AvatarCollection::create(collect($data)->except('organization_ids')->all() + ['tenant_id' => $request->user()->tenant_id, 'created_by' => $request->user()->id]);
        $this->syncOrganizations($c, $data['organization_ids'] ?? [], $request);

        return response()->json($c->fresh(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'avatares.editar');
        $c = $this->ownCollection($request, $id);
        $data = $this->validated($request, partial: true);
        $c->update(collect($data)->except('organization_ids')->all());
        if (array_key_exists('organization_ids', $data)) {
            $this->syncOrganizations($c, $data['organization_ids'] ?? [], $request);
        }

        return response()->json($c->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'avatares.eliminar');
        $c = $this->ownCollection($request, $id);
        $this->detachUsers($c->avatars()->pluck('id')->all());
        $c->delete();

        return response()->json(null, 204);
    }

    /** Sube una o varias imágenes (campo files[]) a la colección. */
    public function addAvatars(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'avatares.crear');
        $c = $this->ownCollection($request, $id);
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:30'], 'files.*' => ['file', 'max:'.AvatarService::GALLERY_MAX_KB]]);

        $created = [];
        foreach ($request->file('files') as $file) {
            try {
                $created[] = $this->avatars->storeGalleryFile($c, $file);
            } catch (RuntimeException $e) {
                abort(422, "«{$file->getClientOriginalName()}»: ".$e->getMessage());
            }
        }

        return response()->json(collect($created)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'is_active' => true, 'url' => $this->avatars->galleryUrl($a)]), 201);
    }

    public function updateAvatar(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'avatares.editar');
        $a = $this->ownAvatar($request, $id);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'is_active' => ['sometimes', 'boolean']]);
        $a->update($data);
        if (isset($data['is_active']) && ! $data['is_active']) {
            $this->detachUsers([$a->id]);
        }

        return response()->json(['id' => $a->id, 'name' => $a->name, 'is_active' => $a->is_active]);
    }

    public function destroyAvatar(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'avatares.eliminar');
        $a = $this->ownAvatar($request, $id);
        $this->detachUsers([$a->id]);
        $a->delete();

        return response()->json(null, 204);
    }

    /** Quienes usaban estos avatares vuelven al avatar por defecto (iniciales). */
    private function detachUsers(array $avatarIds): void
    {
        User::whereIn('avatar_id', $avatarIds)->update(['avatar_id' => null, 'avatar_updated_at' => now()]);
    }

    private function requireGlobal(Request $request, string $permission): void
    {
        abort_unless($this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile')) === null, 403, 'Administrar galerías requiere alcance global.');
    }

    private function ownCollection(Request $request, string $id): AvatarCollection
    {
        $c = AvatarCollection::where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $request->user()->tenant_id))->findOrFail($id);
        abort_if($c->tenant_id === null, 403, 'Las colecciones del sistema no se pueden modificar.');

        return $c;
    }

    private function ownAvatar(Request $request, string $id): Avatar
    {
        $a = Avatar::with('collection')->findOrFail($id);
        $this->ownCollection($request, $a->collection_id);

        return $a;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_global' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'organization_ids' => ['sometimes', 'array'],
            'organization_ids.*' => ['uuid', Rule::exists(config('database.default').'.core.organizations', 'id')->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at')],
        ]);
    }

    private function syncOrganizations(AvatarCollection $c, array $ids, Request $request): void
    {
        $c->organizations()->sync(array_values(array_unique($ids)));
        DB::table('core.avatar_collection_organization')->where('collection_id', $c->id)->update(['created_at' => now(), 'updated_at' => now()]);
    }
}
