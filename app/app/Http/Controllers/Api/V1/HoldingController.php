<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Holding;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Holdings (grupos empresariales). Un holding agrupa organizaciones y es uno de los alcances posibles de un perfil.
 * Se ven solo los que contienen organizaciones dentro del alcance del usuario; crear, editar y eliminar exige alcance global.
 */
class HoldingController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $query = Holding::where('tenant_id', $request->user()->tenant_id)->withCount('organizations')->orderBy('name');
        $allowed = $this->access->allowedOrganizationIds($request->user(), 'holdings.ver', $request->header('X-Profile'));
        if ($allowed !== null) {
            $query->whereHas('organizations', fn ($q) => $q->whereIn('id', $allowed));
        }
        if ($request->filled('q')) {
            $query->where('name', 'ilike', '%'.$request->query('q').'%');
        }

        return response()->json($query->paginate(50));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $holding = $this->find($request, $id, 'holdings.ver');

        return response()->json($holding->loadCount('organizations'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireGlobal($request, 'holdings.crear');
        $holding = Holding::create($this->validated($request) + ['tenant_id' => $request->user()->tenant_id, 'created_by' => $request->user()->id]);

        return response()->json($holding->loadCount('organizations'), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'holdings.editar');
        $holding = $this->find($request, $id, 'holdings.editar');
        $holding->update($this->validated($request, $holding->id) + ['updated_by' => $request->user()->id]);

        return response()->json($holding->loadCount('organizations'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'holdings.eliminar');
        $holding = $this->find($request, $id, 'holdings.eliminar');
        abort_if($holding->organizations()->exists(), 409, 'El holding tiene organizaciones asociadas. Quítelas del holding antes de eliminarlo.');
        $holding->delete();

        return response()->json(null, 204);
    }

    private function find(Request $request, string $id, string $permission): Holding
    {
        $holding = Holding::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $allowed = $this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile'));
        abort_if($allowed !== null && ! $holding->organizations()->whereIn('id', $allowed)->exists(), 403, 'Fuera de su alcance.');

        return $holding;
    }

    private function requireGlobal(Request $request, string $permission): void
    {
        abort_unless($this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile')) === null, 403, 'Administrar holdings requiere alcance global.');
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate(['name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:255',
            Rule::unique(config('database.default').'.core.holdings', 'name')->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at')->ignore($ignoreId)]]);
    }
}
