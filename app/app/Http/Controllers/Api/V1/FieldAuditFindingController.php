<?php

namespace App\Http\Controllers\Api\V1;

use App\FieldAudit\QuestionTypes;
use App\Http\Controllers\Controller;
use App\Models\FieldAudit;
use App\Models\FieldAuditFinding;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Hallazgos de las auditorías y su plan de acción (responsable, acción, plazo, estado). */
class FieldAuditFindingController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'severity' => ['nullable', 'string'], 'audit_id' => ['nullable', 'uuid'], 'organization_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100']]);
        $allowed = $this->access->allowedOrganizationIds($request->user(), 'hallazgos.ver', $request->header('X-Profile'));

        $page = FieldAuditFinding::where('fieldaudit.findings.tenant_id', $request->user()->tenant_id)
            ->join('fieldaudit.audits as a', 'a.id', '=', 'fieldaudit.findings.audit_id')
            ->when($allowed !== null, fn ($q) => $q->whereIn('a.organization_id', $allowed))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('fieldaudit.findings.status', explode(',', $request->query('status'))))
            ->when($request->filled('severity'), fn ($q) => $q->whereIn('severity', explode(',', $request->query('severity'))))
            ->when($request->filled('audit_id'), fn ($q) => $q->where('fieldaudit.findings.audit_id', $request->query('audit_id')))
            ->when($request->filled('organization_id'), fn ($q) => $q->where('a.organization_id', $request->query('organization_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('description', 'ilike', '%'.$request->query('q').'%')->orWhere('a.title', 'ilike', '%'.$request->query('q').'%')))
            ->orderByRaw("case fieldaudit.findings.status when 'closed' then 1 else 0 end")
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderByDesc('fieldaudit.findings.created_at')
            ->select('fieldaudit.findings.*', 'a.title as audit_title', 'a.organization_id', 'a.branch_id')
            ->paginate(30);

        $orgs = DB::table('core.organizations')->whereIn('id', $page->pluck('organization_id'))->pluck('legal_name', 'id');
        $people = DB::table('master.people')->whereIn('id', $page->pluck('responsible_person_id')->filter())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return response()->json($page->through(fn ($f) => $f->toArray() + [
            'organization' => $orgs[$f->organization_id] ?? null,
            'responsible' => isset($people[$f->responsible_person_id]) ? trim($people[$f->responsible_person_id]->first_name.' '.$people[$f->responsible_person_id]->last_name) : null,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audit_id' => ['required', 'uuid'], 'description' => ['required', 'string', 'max:2000'], 'severity' => ['sometimes', Rule::in(QuestionTypes::SEVERITIES)],
            'question_id' => ['nullable', 'string', 'max:40'], 'responsible_person_id' => ['nullable', 'uuid'], 'action_plan' => ['nullable', 'string', 'max:2000'], 'due_on' => ['nullable', 'date'],
        ]);
        $audit = FieldAudit::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['audit_id']);
        abort_unless($this->access->canOnOrganization($request->user(), 'hallazgos.crear', $audit->organization_id, $request->header('X-Profile')), 403, 'Fuera de su alcance.');
        $this->checkPerson($request, $data['responsible_person_id'] ?? null);

        $finding = FieldAuditFinding::create($data + ['tenant_id' => $audit->tenant_id, 'source' => 'manual', 'status' => 'open', 'created_by' => $request->user()->id]);

        return response()->json($finding, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $f = $this->find($request, $id, 'hallazgos.editar');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'closed'])], 'severity' => ['sometimes', Rule::in(QuestionTypes::SEVERITIES)],
            'description' => ['sometimes', 'string', 'max:2000'], 'responsible_person_id' => ['nullable', 'uuid'], 'action_plan' => ['nullable', 'string', 'max:2000'], 'due_on' => ['nullable', 'date'],
        ]);
        $this->checkPerson($request, $data['responsible_person_id'] ?? null);
        if (isset($data['status'])) {
            $data['closed_at'] = $data['status'] === 'closed' ? now() : null;
        }
        $f->update($data + ['updated_by' => $request->user()->id]);

        return response()->json($f);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id, 'hallazgos.eliminar')->delete();

        return response()->json(null, 204);
    }

    private function find(Request $request, string $id, string $permission): FieldAuditFinding
    {
        $f = FieldAuditFinding::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $org = DB::table('fieldaudit.audits')->where('id', $f->audit_id)->value('organization_id');
        abort_unless($this->access->canOnOrganization($request->user(), $permission, $org, $request->header('X-Profile')), 403, 'Fuera de su alcance.');

        return $f;
    }

    private function checkPerson(Request $request, ?string $personId): void
    {
        if ($personId) {
            abort_unless(DB::table('master.people')->where('tenant_id', $request->user()->tenant_id)->where('id', $personId)->whereNull('deleted_at')->exists(), 422, 'La persona responsable no existe.');
        }
    }
}
