<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesSelloutClient;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SelloutChain;
use App\Models\SelloutPoint;
use App\Models\SelloutPointNote;
use App\Models\SelloutPointPhoto;
use App\Support\Access\AccessService;
use App\Support\Media\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Sell-out: clientes, datos del tablero, cadenas, salas, fotos y comentarios de terreno. */
class SelloutController extends Controller
{
    use ResolvesSelloutClient;

    public function __construct(private AccessService $access) {}

    /** Clientes (organizaciones) que el usuario puede ver en el módulo. */
    public function clients(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = $this->access->allowedOrganizationIds($user, 'sellout.ver', $request->header('X-Profile'));
        $q = DB::table('core.organizations as o')->where('o.tenant_id', $user->tenant_id)->whereNull('o.deleted_at')
            ->where(fn ($w) => $w->where('o.type', 'client')->orWhereExists(fn ($e) => $e->selectRaw('1')->from('sellout.chains as c')->whereColumn('c.organization_id', 'o.id')))
            ->when($allowed !== null, fn ($w) => $w->whereIn('o.id', $allowed))->orderBy('o.legal_name');

        return response()->json($q->get(['o.id', 'o.legal_name', 'o.trade_name'])->map(fn ($o) => ['id' => $o->id, 'name' => $o->trade_name ?: $o->legal_name]));
    }

    /**
     * Datos del tablero de un cliente en formato compacto: meses, cadenas, productos y, por sala, la venta mensual por producto
     * [venta, unidades, costo] y las metas por mes. La agregación fina (períodos, comparaciones) se hace en el navegador.
     */
    public function dataset(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout.ver');
        $range = DB::table('sellout.sales')->where('organization_id', $org)->selectRaw("min(period_start) a, max(period_start) b")->first();
        $tr = DB::table('sellout.targets')->where('organization_id', $org)->selectRaw('min(period_start) a, max(period_start) b')->first();
        $from = collect([$range->a, $tr->a])->filter()->min();
        $to = collect([$range->b, $tr->b])->filter()->max();
        $months = [];
        if ($from) {
            for ($d = \Carbon\Carbon::parse($from)->startOfMonth(), $end = \Carbon\Carbon::parse($to)->startOfMonth(); $d <= $end; $d = $d->copy()->addMonth()) {
                $months[] = $d->format('Y-m');
            }
        }
        $idx = array_flip($months);
        $n = count($months);

        $chains = SelloutChain::where('organization_id', $org)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'color']);
        $products = \App\Models\SelloutProduct::where('organization_id', $org)->where('active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color, 'upb' => $p->units_per_box, 'image' => $p->image_path ? $this->signed('media.sellout-product', ['product' => $p->id]) : null]);

        $stores = [];
        foreach (SelloutPoint::where('organization_id', $org)->where('status', 'active')->get() as $p) {
            $stores[$p->id] = ['id' => $p->id, 'ch' => $p->chain_id, 'code' => $p->code, 'n' => $p->name, 'reg' => $p->region, 'com' => $p->commune, 'lat' => $p->latitude, 'lon' => $p->longitude, 'q' => $p->geo_quality, 'ad' => $p->address, 'd' => [], 't' => []];
        }
        $rows = DB::table('sellout.sales')->where('organization_id', $org)
            ->selectRaw("point_id, product_id, to_char(period_start, 'YYYY-MM') as m, sum(units) as u, sum(sales) as s, coalesce(sum(cost), 0) as c")->groupBy('point_id', 'product_id', 'm')->cursor();
        foreach ($rows as $r) {
            if (! isset($stores[$r->point_id], $idx[$r->m])) {
                continue;
            }
            $a = &$stores[$r->point_id]['d'][$r->product_id];
            $a ??= [array_fill(0, $n, 0), array_fill(0, $n, 0), array_fill(0, $n, 0)];
            [$a[0][$idx[$r->m]], $a[1][$idx[$r->m]], $a[2][$idx[$r->m]]] = [(int) round($r->s), round((float) $r->u, 2), (int) round($r->c)];
            unset($a);
        }
        foreach (DB::table('sellout.targets')->where('organization_id', $org)->selectRaw("point_id, to_char(period_start, 'YYYY-MM') as m, boxes")->cursor() as $t) {
            if (isset($stores[$t->point_id], $idx[$t->m])) {
                $stores[$t->point_id]['t'][$idx[$t->m]] = (float) $t->boxes;
            }
        }

        return response()->json(['client' => ['id' => $org], 'months' => $months, 'chains' => $chains, 'products' => $products, 'stores' => array_values($stores)]);
    }

    // ───────────── cadenas ─────────────

    public function chains(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout.ver');

        return response()->json(SelloutChain::where('organization_id', $org)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'color', 'sort']));
    }

    public function storeChain(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout.editar');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        abort_if(SelloutChain::where('organization_id', $org)->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->exists(), 422, 'Ya existe una cadena con ese nombre.');
        $c = SelloutChain::create($data + ['tenant_id' => $request->user()->tenant_id, 'organization_id' => $org]);

        return response()->json($c, 201);
    }

    public function updateChain(Request $request, string $id): JsonResponse
    {
        $c = SelloutChain::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, 'sellout.editar', $c->organization_id);
        $c->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'sort' => ['sometimes', 'integer']]));

        return response()->json($c);
    }

    // ───────────── salas ─────────────

    public function points(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout.ver');
        $q = SelloutPoint::where('organization_id', $org)
            ->when($request->filled('chain_id'), fn ($w) => $w->where('chain_id', $request->query('chain_id')))
            ->when($request->filled('quality'), fn ($w) => $w->where('geo_quality', $request->query('quality')))
            ->when($request->filled('q'), fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', '%'.$request->query('q').'%')->orWhere('code', 'ilike', '%'.$request->query('q').'%')->orWhere('address', 'ilike', '%'.$request->query('q').'%')))
            ->orderBy('name');

        return response()->json($q->paginate(50));
    }

    public function updatePoint(Request $request, string $id): JsonResponse
    {
        $p = SelloutPoint::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, 'sellout.editar', $p->organization_id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:255'], 'commune' => ['nullable', 'string', 'max:255'], 'region' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'status' => ['sometimes', 'in:active,inactive'],
        ]);
        if (isset($data['latitude'], $data['longitude'])) {
            $data['geo_quality'] = 'exact';
        }
        $before = $p->only(array_keys($data));
        $p->update($data + ['updated_by' => $request->user()->id]);
        AuditEvent::record('sellout_point_updated', $p, $before, $data);

        return response()->json($p);
    }

    /** Ficha de la sala: fotos, comentarios y solicitudes. */
    public function pointDetail(Request $request, string $id): JsonResponse
    {
        $p = SelloutPoint::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, 'sellout.ver', $p->organization_id);
        $users = fn ($ids) => DB::table('security.users')->whereIn('id', $ids)->pluck('email', 'id');
        $photos = SelloutPointPhoto::where('point_id', $p->id)->latest()->get();
        $notes = SelloutPointNote::where('point_id', $p->id)->latest()->get();
        $uname = $users($photos->pluck('user_id')->merge($notes->pluck('user_id'))->unique());

        return response()->json([
            'id' => $p->id,
            'photos' => $photos->map(fn ($f) => ['id' => $f->id, 'url' => $this->signed('media.sellout-photo', ['photo' => $f->id]), 'caption' => $f->caption, 'by' => $uname[$f->user_id] ?? null, 'at' => $f->created_at]),
            'notes' => $notes->map(fn ($n) => ['id' => $n->id, 'body' => $n->body, 'by' => $uname[$n->user_id] ?? null, 'at' => $n->created_at]),
        ]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $p = SelloutPoint::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, 'sellout.campo', $p->organization_id);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $n = SelloutPointNote::create(['tenant_id' => $p->tenant_id, 'organization_id' => $p->organization_id, 'point_id' => $p->id, 'user_id' => $request->user()->id, 'body' => $data['body']]);

        return response()->json(['id' => $n->id, 'body' => $n->body, 'by' => $request->user()->email, 'at' => $n->created_at], 201);
    }

    public function addPhoto(Request $request, string $id): JsonResponse
    {
        $p = SelloutPoint::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, 'sellout.campo', $p->organization_id);
        $request->validate(['file' => ['required', 'file', 'max:15360'], 'caption' => ['nullable', 'string', 'max:200']]);
        try {
            $img = ImageStore::store($request->file('file'), "sellout/{$p->tenant_id}/{$p->id}", 1600);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $f = SelloutPointPhoto::create(['tenant_id' => $p->tenant_id, 'organization_id' => $p->organization_id, 'point_id' => $p->id, 'user_id' => $request->user()->id, 'path' => $img['path'], 'mime' => $img['mime'], 'size' => $img['size'], 'caption' => $request->input('caption')]);

        return response()->json(['id' => $f->id, 'url' => $this->signed('media.sellout-photo', ['photo' => $f->id]), 'caption' => $f->caption, 'by' => $request->user()->email, 'at' => $f->created_at], 201);
    }

    public function deletePhoto(Request $request, string $id): JsonResponse
    {
        $f = SelloutPointPhoto::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, 'sellout.campo', $f->organization_id);
        Storage::disk('local')->delete($f->path);
        $f->delete();

        return response()->json(null, 204);
    }

    private function signed(string $route, array $params): string
    {
        return URL::temporarySignedRoute($route, now()->startOfHour()->addHours(12), $params, absolute: false);
    }
}
