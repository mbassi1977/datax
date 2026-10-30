<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CatalogType;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Catálogos maestros y constructor de catálogos.
 * Hay catálogos del sistema (solo lectura; algunos admiten entradas propias del tenant) y catálogos creados por el tenant.
 * Los catálogos son configuración del tenant completo: escribir exige alcance global.
 */
class CatalogController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant_id;
        $counts = CatalogItem::where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant))
            ->selectRaw('catalog, count(*) as n')->groupBy('catalog')->pluck('n', 'catalog');

        return response()->json($this->types($tenant)->map(fn ($t) => [
            'key' => $t->key, 'label' => $t->label, 'description' => $t->description, 'is_system' => $t->tenant_id === null,
            'allow_tenant_items' => $t->tenant_id !== null || $t->allow_tenant_items, 'items_count' => (int) ($counts[$t->key] ?? 0),
        ])->values());
    }

    public function items(Request $request, string $key): JsonResponse
    {
        $type = $this->type($request, $key);

        return response()->json($this->visibleItems($request, $type->key)->get()->map(fn ($i) => $this->present($i)));
    }

    /** Opciones activas para los formularios (cualquier usuario autenticado). */
    public function options(Request $request, string $key): JsonResponse
    {
        $type = $this->type($request, $key);

        return response()->json($this->visibleItems($request, $type->key)->where('active', true)->get(['code', 'label']));
    }

    /** Crea un catálogo nuevo del tenant. */
    public function storeType(Request $request): JsonResponse
    {
        $this->requireGlobal($request, 'catalogos.crear');
        $data = $request->validate(['label' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500']]);
        $tenant = $request->user()->tenant_id;

        $base = Str::slug($data['label'], '_') ?: 'catalogo';
        $key = $base;
        for ($i = 2; $this->types($tenant)->contains('key', $key); $i++) {
            $key = "{$base}_{$i}";
        }
        $type = CatalogType::create(['tenant_id' => $tenant, 'key' => $key, 'label' => $data['label'], 'description' => $data['description'] ?? null]);

        return response()->json(['key' => $type->key, 'label' => $type->label, 'description' => $type->description, 'is_system' => false, 'allow_tenant_items' => true, 'items_count' => 0], 201);
    }

    public function updateType(Request $request, string $key): JsonResponse
    {
        $this->requireGlobal($request, 'catalogos.editar');
        $type = $this->ownType($request, $key);
        $type->update($request->validate(['label' => ['sometimes', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500']]));

        return response()->json($type);
    }

    public function destroyType(Request $request, string $key): JsonResponse
    {
        $this->requireGlobal($request, 'catalogos.eliminar');
        $type = $this->ownType($request, $key);
        DB::transaction(function () use ($type, $request) {
            CatalogItem::where('tenant_id', $request->user()->tenant_id)->where('catalog', $type->key)->get()->each->delete();
            $type->delete();
        });

        return response()->json(null, 204);
    }

    public function storeItem(Request $request, string $key): JsonResponse
    {
        $this->requireGlobal($request, 'catalogos.crear');
        $type = $this->type($request, $key);
        abort_unless($type->tenant_id !== null || $type->allow_tenant_items, 403, 'Este catálogo del sistema no admite entradas propias.');

        $tenant = $request->user()->tenant_id;
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $code = $data['code'] ?? (Str::slug($data['label'], '_') ?: 'item');
        abort_if($this->visibleItems($request, $type->key)->where('code', $code)->exists(), 422, "Ya existe una entrada con el código «{$code}».");

        $item = CatalogItem::create([
            'tenant_id' => $tenant, 'catalog' => $type->key, 'code' => $code, 'label' => $data['label'], 'active' => $data['active'] ?? true,
            'sort' => $data['sort'] ?? ((int) $this->visibleItems($request, $type->key)->max('sort') + 1), 'created_by' => $request->user()->id,
        ]);

        return response()->json($this->present($item), 201);
    }

    public function updateItem(Request $request, string $key, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'catalogos.editar');
        $item = $this->ownItem($request, $key, $id);
        $item->update($request->validate([
            'label' => ['sometimes', 'string', 'max:255'], 'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'], 'active' => ['sometimes', 'boolean'],
        ]) + ['updated_by' => $request->user()->id]);

        return response()->json($this->present($item));
    }

    public function destroyItem(Request $request, string $key, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'catalogos.eliminar');
        $this->ownItem($request, $key, $id)->delete();

        return response()->json(null, 204);
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function types(string $tenant)
    {
        return CatalogType::where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant))->orderByRaw('tenant_id is null desc')->orderBy('label')->get();
    }

    private function type(Request $request, string $key): CatalogType
    {
        return $this->types($request->user()->tenant_id)->firstWhere('key', $key) ?? abort(404, 'Catálogo inexistente.');
    }

    private function ownType(Request $request, string $key): CatalogType
    {
        $type = $this->type($request, $key);
        abort_if($type->tenant_id === null, 403, 'Los catálogos del sistema no se pueden modificar.');

        return $type;
    }

    private function visibleItems(Request $request, string $catalog)
    {
        return CatalogItem::where('catalog', $catalog)->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $request->user()->tenant_id))->orderBy('sort')->orderBy('label');
    }

    private function ownItem(Request $request, string $key, string $id): CatalogItem
    {
        $this->type($request, $key);
        $item = $this->visibleItems($request, $key)->findOrFail($id);
        abort_if($item->tenant_id === null, 403, 'Las entradas del sistema no se pueden modificar.');

        return $item;
    }

    private function requireGlobal(Request $request, string $permission): void
    {
        abort_unless($this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile')) === null, 403, 'Administrar catálogos requiere alcance global.');
    }

    private function present(CatalogItem $i): array
    {
        return ['id' => $i->id, 'code' => $i->code, 'label' => $i->label, 'sort' => $i->sort, 'active' => $i->active, 'is_system' => $i->tenant_id === null];
    }
}
