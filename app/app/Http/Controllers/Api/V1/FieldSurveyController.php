<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FieldAudit;
use App\Models\FieldAuditTemplate;
use App\Models\FieldSurvey;
use App\Models\User;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Levantamientos en terreno: campañas que reparten una plantilla publicada entre varios locales y colectores.
 * Cada local asignado es una auditoría (con su copia de la plantilla) que el colector ejecuta desde la app,
 * con o sin conexión (ver SyncController).
 */
class FieldSurveyController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:active,closed'], 'q' => ['nullable', 'string', 'max:100']]);
        $page = $this->visible($request)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'ilike', '%'.$request->query('q').'%'))
            ->orderByDesc('created_at')->paginate(30);

        return response()->json($page->through(fn ($s) => $this->present($s, false)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->present($this->visible($request)->findOrFail($id), true));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'template_id' => ['required', 'uuid'], 'organization_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $this->mayManage($request, $data['organization_id']);
        abort_unless(DB::table('core.organizations')->where('tenant_id', $user->tenant_id)->where('id', $data['organization_id'])->whereNull('deleted_at')->exists(), 422, 'La organización no existe.');
        $template = FieldAuditTemplate::where('tenant_id', $user->tenant_id)->find($data['template_id']);
        abort_unless($template && $template->status === 'published', 422, 'Solo se pueden usar plantillas publicadas.');

        $s = FieldSurvey::create($data + ['tenant_id' => $user->tenant_id, 'status' => 'active', 'created_by' => $user->id]);

        return response()->json($this->present($s, true), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $s = $this->visible($request)->findOrFail($id);
        $this->mayManage($request, $s->organization_id);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date']]);
        $s->update($data + ['updated_by' => $request->user()->id]);

        return response()->json($this->present($s, true));
    }

    /** Asigna locales a colectores. Un local no se asigna dos veces en el mismo levantamiento (salvo que la anterior se cancelara). */
    public function assign(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $s = $this->visible($request)->findOrFail($id);
        $this->mayManage($request, $s->organization_id);
        abort_unless($s->status === 'active', 409, 'El levantamiento está cerrado.');
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1', 'max:500'], 'assignments.*.branch_id' => ['required', 'uuid'], 'assignments.*.auditor_id' => ['required', 'uuid'],
            'scheduled_for' => ['nullable', 'date'], 'due_on' => ['nullable', 'date'],
        ]);
        $template = FieldAuditTemplate::where('tenant_id', $user->tenant_id)->find($s->template_id);
        abort_unless($template, 422, 'La plantilla ya no existe.');

        $branches = DB::table('core.branches')->where('tenant_id', $user->tenant_id)->where('organization_id', $s->organization_id)->whereNull('deleted_at')
            ->whereIn('id', collect($data['assignments'])->pluck('branch_id'))->pluck('name', 'id');
        $taken = FieldAudit::where('survey_id', $s->id)->where('status', '!=', 'cancelled')->pluck('branch_id')->all();
        $auditors = [];
        $created = [];
        $skipped = [];

        foreach ($data['assignments'] as $a) {
            if (! isset($branches[$a['branch_id']])) {
                $skipped[] = ['branch_id' => $a['branch_id'], 'reason' => 'La sucursal no pertenece a la organización.'];
                continue;
            }
            if (in_array($a['branch_id'], $taken, true)) {
                $skipped[] = ['branch_id' => $a['branch_id'], 'reason' => 'Ya está asignada en este levantamiento.'];
                continue;
            }
            $auditors[$a['auditor_id']] ??= ($u = User::where('tenant_id', $user->tenant_id)->where('status', 'active')->find($a['auditor_id'])) && $this->access->canOnOrganization($u, 'auditorias.ejecutar', $s->organization_id);
            if (! $auditors[$a['auditor_id']]) {
                $skipped[] = ['branch_id' => $a['branch_id'], 'reason' => 'El colector no puede ejecutar levantamientos en esta organización.'];
                continue;
            }
            $audit = FieldAudit::create([
                'tenant_id' => $user->tenant_id, 'survey_id' => $s->id, 'template_id' => $template->id, 'template_name' => $template->name, 'template_version' => $template->version,
                'pass_threshold' => $template->pass_threshold, 'scoring_enabled' => (bool) $template->scoring_enabled, 'structure' => $template->structure,
                'title' => "{$s->name} · {$branches[$a['branch_id']]}", 'organization_id' => $s->organization_id, 'branch_id' => $a['branch_id'], 'auditor_id' => $a['auditor_id'],
                'scheduled_for' => $data['scheduled_for'] ?? $s->starts_on ?? now()->toDateString(), 'due_on' => $data['due_on'] ?? $s->ends_on, 'status' => 'scheduled', 'created_by' => $user->id,
            ]);
            $taken[] = $a['branch_id'];
            $created[] = $audit->id;
        }

        return response()->json(['created' => $created, 'skipped' => $skipped, 'survey' => $this->present($s, true)], 201);
    }

    /** Cierra el levantamiento: lo que no se ejecutó se cancela; lo enviado queda para revisión. */
    public function close(Request $request, string $id): JsonResponse
    {
        $s = $this->visible($request)->findOrFail($id);
        $this->mayManage($request, $s->organization_id);
        abort_unless($s->status === 'active', 409, 'El levantamiento ya está cerrado.');
        DB::transaction(function () use ($s, $request) {
            FieldAudit::where('survey_id', $s->id)->whereIn('status', ['scheduled'])->update(['status' => 'cancelled', 'updated_by' => $request->user()->id]);
            $s->update(['status' => 'closed', 'updated_by' => $request->user()->id]);
        });

        return response()->json($this->present($s, true));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $s = $this->visible($request)->findOrFail($id);
        abort_unless($this->access->canOnOrganization($request->user(), 'levantamientos.eliminar', $s->organization_id, $request->header('X-Profile')), 403, 'Fuera de su alcance.');
        abort_if(FieldAudit::where('survey_id', $s->id)->whereNotIn('status', ['scheduled', 'cancelled'])->exists(), 409, 'Ya tiene auditorías en curso o enviadas: ciérrelo en vez de eliminarlo.');
        FieldAudit::where('survey_id', $s->id)->delete();
        $s->delete();

        return response()->json(null, 204);
    }

    private function mayManage(Request $request, string $orgId): void
    {
        abort_unless($this->access->canOnOrganization($request->user(), 'levantamientos.crear', $orgId, $request->header('X-Profile')), 403, 'Fuera de su alcance.');
    }

    private function visible(Request $request)
    {
        $allowed = $this->access->allowedOrganizationIds($request->user(), 'levantamientos.ver', $request->header('X-Profile'));

        return FieldSurvey::where('tenant_id', $request->user()->tenant_id)->when($allowed !== null, fn ($q) => $q->whereIn('organization_id', $allowed));
    }

    private function present(FieldSurvey $s, bool $withAudits): array
    {
        $rows = FieldAudit::where('survey_id', $s->id)->get(['id', 'title', 'status', 'branch_id', 'auditor_id', 'scheduled_for', 'due_on', 'score', 'result', 'started_at', 'submitted_at']);
        $counts = $rows->countBy('status');
        $total = $rows->where('status', '!=', 'cancelled')->count();
        $done = $rows->whereIn('status', ['submitted', 'approved'])->count();
        $out = $s->toArray() + [
            'organization' => DB::table('core.organizations')->where('id', $s->organization_id)->value('legal_name'),
            'template' => DB::table('fieldaudit.templates')->where('id', $s->template_id)->value('name'),
            'progress' => ['total' => $total, 'done' => $done, 'percent' => $total ? round($done * 100 / $total) : 0, 'by_status' => $counts],
        ];
        if ($withAudits) {
            $branches = DB::table('core.branches')->whereIn('id', $rows->pluck('branch_id')->filter())->pluck('name', 'id');
            $users = DB::table('security.users')->whereIn('id', $rows->pluck('auditor_id'))->pluck('email', 'id');
            $out['audits'] = $rows->map(fn ($a) => $a->toArray() + ['branch' => $branches[$a->branch_id] ?? null, 'auditor' => $users[$a->auditor_id] ?? null])->values();
        }

        return $out;
    }
}
