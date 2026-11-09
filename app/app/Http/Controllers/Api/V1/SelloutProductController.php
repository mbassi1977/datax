<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesSelloutClient;
use App\Http\Controllers\Controller;
use App\Models\SelloutProduct;
use App\Support\Access\AccessService;
use App\Support\Media\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Catálogo de productos del cliente: nombre, equivalencias con cada cadena (alias), color e imagen. */
class SelloutProductController extends Controller
{
    use ResolvesSelloutClient;

    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout.ver');

        return response()->json(SelloutProduct::where('organization_id', $org)->orderBy('sort')->orderBy('name')->get()->map(fn ($p) => $this->present($p)));
    }

    public function store(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout.editar');
        $data = $this->validated($request, $org);
        $p = SelloutProduct::create($data + ['tenant_id' => $request->user()->tenant_id, 'organization_id' => $org]);

        return response()->json($this->present($p), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id);
        $p->update($this->validated($request, $p->organization_id, $p->id, true));

        return response()->json($this->present($p->refresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id);
        abort_if(\DB::table('sellout.sales')->where('product_id', $p->id)->exists(), 409, 'El producto tiene ventas cargadas: desactívelo en vez de eliminarlo.');
        $p->image_path && Storage::disk('local')->delete($p->image_path);
        $p->delete();

        return response()->json(null, 204);
    }

    /** Imagen del producto (PNG/WebP con transparencia recomendado). */
    public function image(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id);
        $request->validate(['file' => ['required', 'file', 'max:8192']]);
        try {
            $img = ImageStore::store($request->file('file'), "sellout/{$p->tenant_id}/products", 700, true);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $p->image_path && Storage::disk('local')->delete($p->image_path);
        $p->update(['image_path' => $img['path'], 'image_mime' => $img['mime']]);

        return response()->json($this->present($p->refresh()));
    }

    public function removeImage(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id);
        $p->image_path && Storage::disk('local')->delete($p->image_path);
        $p->update(['image_path' => null, 'image_mime' => null]);

        return response()->json($this->present($p->refresh()));
    }

    private function find(Request $request, string $id): SelloutProduct
    {
        $p = SelloutProduct::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, 'sellout.editar', $p->organization_id);

        return $p;
    }

    private function validated(Request $request, string $org, ?string $ignore = null, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';
        $d = $request->validate([
            'name' => [$r, 'string', 'max:150'], 'aliases' => ['sometimes', 'array', 'max:30'], 'aliases.*' => ['string', 'max:150'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'units_per_box' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'sort' => ['sometimes', 'integer'], 'active' => ['sometimes', 'boolean'],
        ]);
        if (isset($d['name']) && SelloutProduct::where('organization_id', $org)->whereRaw('lower(name) = ?', [mb_strtolower($d['name'])])->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            abort(422, 'Ya existe un producto con ese nombre.');
        }
        if (isset($d['aliases'])) {
            $d['aliases'] = array_values(array_unique(array_filter(array_map('trim', $d['aliases']))));
        }

        return $d;
    }

    private function present(SelloutProduct $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'aliases' => $p->aliases ?? [], 'color' => $p->color, 'units_per_box' => $p->units_per_box, 'sort' => $p->sort, 'active' => $p->active,
            'image' => $p->image_path ? URL::temporarySignedRoute('media.sellout-product', now()->startOfHour()->addHours(12), ['product' => $p->id, 'v' => $p->updated_at?->timestamp], absolute: false) : null];
    }
}
