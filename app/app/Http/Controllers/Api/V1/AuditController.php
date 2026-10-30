<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Access\AccessService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consulta y exportación de la bitácora de auditoría (quién, qué, cuándo, desde dónde, valor anterior y nuevo).
 * Es información sensible: exige alcance global y toda exportación queda registrada.
 */
class AuditController extends Controller
{
    private const EXPORT_LIMIT = 20000;

    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireGlobal($request);
        $page = $this->query($request)->orderByDesc('a.created_at')->paginate(50);

        return response()->json($page->through(fn ($r) => $this->row($r)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request);
        $row = $this->base($request)->where('a.id', $id)->first() ?? abort(404);

        return response()->json($this->row($row) + ['old_values' => json_decode($row->old_values ?? 'null', true), 'new_values' => json_decode($row->new_values ?? 'null', true), 'user_agent' => $row->user_agent]);
    }

    /** Valores para los filtros: acciones, tipos de entidad y usuarios que aparecen en la bitácora del tenant. */
    public function meta(Request $request): JsonResponse
    {
        $this->requireGlobal($request);
        $tenant = $request->user()->tenant_id;
        $base = DB::table('audit.audit_events')->where('tenant_id', $tenant);

        return response()->json([
            'actions' => (clone $base)->distinct()->orderBy('action')->pluck('action'),
            'entity_types' => (clone $base)->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'users' => DB::table('security.users')->where('tenant_id', $tenant)->whereIn('id', (clone $base)->whereNotNull('user_id')->select('user_id'))->orderBy('email')->get(['id', 'email']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->requireGlobal($request);
        $query = $this->query($request)->orderByDesc('a.created_at')->limit(self::EXPORT_LIMIT);
        $count = (clone $query)->count();
        AuditEvent::record('audit_exported', null, null, ['filters' => $request->only(['action', 'entity_type', 'user_id', 'from', 'to', 'q']), 'rows' => min($count, self::EXPORT_LIMIT)]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha (UTC)', 'Usuario', 'Acción', 'Tipo de entidad', 'ID entidad', 'IP', 'Valor anterior', 'Valor nuevo'], ';');
            $query->get()->each(fn ($r) => fputcsv($out, [$r->created_at, $r->user_email, $r->action, $r->entity_type, $r->entity_id, $r->ip_address, $r->old_values, $r->new_values], ';'));
            fclose($out);
        }, 'auditoria-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function requireGlobal(Request $request): void
    {
        abort_unless($this->access->allowedOrganizationIds($request->user(), 'auditoria.ver', $request->header('X-Profile')) === null, 403, 'Consultar la auditoría requiere alcance global.');
    }

    private function base(Request $request): Builder
    {
        return DB::table('audit.audit_events as a')->leftJoin('security.users as u', 'u.id', '=', 'a.user_id')
            ->where('a.tenant_id', $request->user()->tenant_id)
            ->select('a.id', 'a.created_at', 'a.action', 'a.entity_type', 'a.entity_id', 'a.ip_address', 'a.user_agent', 'a.old_values', 'a.new_values', 'a.user_id', 'u.email as user_email');
    }

    private function query(Request $request): Builder
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'user_id' => ['nullable', 'uuid'], 'action' => ['nullable', 'string', 'max:50'], 'entity_type' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:100']]);

        return $this->base($request)
            ->when($request->filled('action'), fn ($q) => $q->where('a.action', $request->query('action')))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('a.entity_type', $request->query('entity_type')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('a.user_id', $request->query('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('a.created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('a.created_at', '<', date('Y-m-d', strtotime($request->query('to').' +1 day'))))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->query('q').'%';
                $q->where(fn ($w) => $w->where('u.email', 'ilike', $term)->orWhere('a.action', 'ilike', $term)->orWhere('a.entity_type', 'ilike', $term)->orWhereRaw('a.entity_id::text ilike ?', [$term]));
            });
    }

    private function row(object $r): array
    {
        return ['id' => $r->id, 'created_at' => $r->created_at, 'user_id' => $r->user_id, 'user_email' => $r->user_email, 'action' => $r->action,
            'entity_type' => $r->entity_type, 'entity_id' => $r->entity_id, 'ip_address' => $r->ip_address];
    }
}
