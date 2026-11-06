<?php

namespace App\Http\Controllers\Api\V1;

use App\FieldAudit\AnswerValidator;
use App\FieldAudit\Scorer;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\FieldAudit;
use App\Models\FieldAuditAnswer;
use App\Models\FieldAuditEvidence;
use App\Models\FieldAuditFinding;
use App\Models\FieldAuditTemplate;
use App\Models\User;
use App\Support\Access\AccessService;
use App\Support\FieldAudit\EvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Auditorías en terreno: asignación, ejecución por el auditor, envío con puntaje automático y revisión.
 *
 *  scheduled → in_progress → submitted → approved
 *                  ↑             ↓
 *                  └──── returned (el revisor la devuelve con observaciones)
 *  cancelled: desde scheduled / in_progress / returned.
 *
 * Permisos: ver · crear (asignar) · editar (reprogramar) · eliminar (cancelar) · ejecutar (solo sus auditorías) · aprobar.
 * Quien no puede crear ni aprobar solo ve las auditorías que tiene asignadas.
 */
class FieldAuditController extends Controller
{
    public function __construct(private AccessService $access, private EvidenceService $evidence) {}

    // ───────────────────────────── consulta ─────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'organization_id' => ['nullable', 'uuid'], 'auditor_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100']]);
        $page = $this->visible($request)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->query('status'))))
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->query('organization_id')))
            ->when($request->filled('auditor_id'), fn ($q) => $q->where('auditor_id', $request->query('auditor_id')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('auditor_id', $request->user()->id))
            ->when($request->filled('from'), fn ($q) => $q->where('scheduled_for', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('scheduled_for', '<=', $request->query('to')))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'ilike', '%'.$request->query('q').'%'))
            ->orderByRaw("case status when 'in_progress' then 0 when 'returned' then 1 when 'scheduled' then 2 when 'submitted' then 3 else 4 end")
            ->orderByDesc('scheduled_for')->paginate(30, ['id', 'title', 'status', 'organization_id', 'branch_id', 'auditor_id', 'scheduled_for', 'due_on', 'score', 'result', 'scoring_enabled', 'critical_failures', 'submitted_at', 'template_name',
                DB::raw('(select count(*) from fieldaudit.findings f where f.audit_id = fieldaudit.audits.id and f.deleted_at is null) as findings_count')]);

        $orgs = DB::table('core.organizations')->whereIn('id', $page->pluck('organization_id'))->pluck('legal_name', 'id');
        $branches = DB::table('core.branches')->whereIn('id', $page->pluck('branch_id')->filter())->pluck('name', 'id');
        $users = DB::table('security.users')->whereIn('id', $page->pluck('auditor_id'))->pluck('email', 'id');

        return response()->json($page->through(fn ($a) => $a->toArray() + ['organization' => $orgs[$a->organization_id] ?? null, 'branch' => $branches[$a->branch_id] ?? null, 'auditor' => $users[$a->auditor_id] ?? null]));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->present($this->findVisible($request, $id)));
    }

    /** Usuarios que pueden ejecutar auditorías en una organización (para elegir al auditor al asignar). */
    public function auditors(Request $request): JsonResponse
    {
        $request->validate(['organization_id' => ['required', 'uuid']]);
        abort_unless($this->access->canOnOrganization($request->user(), 'auditorias.crear', $request->query('organization_id'), $request->header('X-Profile')), 403, 'Fuera de su alcance.');

        $users = User::where('tenant_id', $request->user()->tenant_id)->where('status', 'active')->orderBy('email')->limit(300)->get()
            ->filter(fn ($u) => $this->access->canOnOrganization($u, 'auditorias.ejecutar', $request->query('organization_id')));

        return response()->json($users->values()->map(fn ($u) => ['id' => $u->id, 'email' => $u->email]));
    }

    // ───────────────────────────── asignación ─────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'template_id' => ['required', 'uuid'], 'organization_id' => ['required', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'],
            'auditor_id' => ['required', 'uuid'], 'scheduled_for' => ['nullable', 'date'], 'due_on' => ['nullable', 'date', 'after_or_equal:scheduled_for'], 'title' => ['nullable', 'string', 'max:200'],
        ]);
        abort_unless($this->access->canOnOrganization($user, 'auditorias.crear', $data['organization_id'], $request->header('X-Profile')), 403, 'Fuera de su alcance.');
        $this->checkRelations($user->tenant_id, $data);

        $template = FieldAuditTemplate::where('tenant_id', $user->tenant_id)->find($data['template_id']);
        abort_unless($template, 422, 'La plantilla no existe.');
        abort_unless($template->status === 'published', 422, 'Solo se pueden asignar plantillas publicadas.');
        $this->checkAuditor($user->tenant_id, $data['auditor_id'], $data['organization_id']);

        $place = ($data['branch_id'] ?? null) ? DB::table('core.branches')->where('id', $data['branch_id'])->value('name') : DB::table('core.organizations')->where('id', $data['organization_id'])->value('legal_name');
        $audit = FieldAudit::create([
            'tenant_id' => $user->tenant_id, 'template_id' => $template->id, 'template_name' => $template->name, 'template_version' => $template->version,
            'pass_threshold' => $template->pass_threshold, 'scoring_enabled' => (bool) $template->scoring_enabled, 'structure' => $template->structure,                           // copia: editar la plantilla no la altera
            'title' => $data['title'] ?? "{$template->name} · {$place}", 'organization_id' => $data['organization_id'], 'branch_id' => $data['branch_id'] ?? null,
            'project_id' => $data['project_id'] ?? null, 'auditor_id' => $data['auditor_id'], 'scheduled_for' => $data['scheduled_for'] ?? now()->toDateString(),
            'due_on' => $data['due_on'] ?? null, 'status' => 'scheduled', 'created_by' => $user->id,
        ]);

        $this->notify($audit, 'audit_assigned', ['when' => $audit->scheduled_for?->format('d/m/Y'), 'due' => $audit->due_on?->format('d/m/Y')]);

        return response()->json($this->present($audit), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $audit = $this->findVisible($request, $id);
        abort_unless($this->access->canOnOrganization($request->user(), 'auditorias.editar', $audit->organization_id, $request->header('X-Profile')), 403, 'Fuera de su alcance.');
        abort_unless(in_array($audit->status, ['scheduled', 'in_progress', 'returned'], true), 409, 'Esta auditoría ya no se puede modificar.');

        $data = $request->validate(['auditor_id' => ['sometimes', 'uuid'], 'scheduled_for' => ['sometimes', 'date'], 'due_on' => ['nullable', 'date'], 'title' => ['sometimes', 'string', 'max:200'], 'branch_id' => ['nullable', 'uuid']]);
        $this->checkRelations($audit->tenant_id, $data + ['organization_id' => $audit->organization_id]);
        isset($data['auditor_id']) && $this->checkAuditor($audit->tenant_id, $data['auditor_id'], $audit->organization_id);
        $audit->update($data + ['updated_by' => $request->user()->id]);

        return response()->json($this->present($audit));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $audit = $this->findVisible($request, $id);
        abort_unless($this->access->canOnOrganization($request->user(), 'auditorias.eliminar', $audit->organization_id, $request->header('X-Profile')), 403, 'Fuera de su alcance.');
        abort_unless(in_array($audit->status, ['scheduled', 'in_progress', 'returned'], true), 409, 'Solo se pueden cancelar auditorías sin enviar.');
        $audit->update(['status' => 'cancelled', 'updated_by' => $request->user()->id]);

        return response()->json($this->present($audit));
    }

    // ───────────────────────────── ejecución (auditor) ─────────────────────────────

    public function start(Request $request, string $id): JsonResponse
    {
        $audit = $this->ownAudit($request, $id);
        abort_unless(in_array($audit->status, ['scheduled', 'in_progress', 'returned'], true), 409, 'Esta auditoría ya fue enviada.');
        $loc = $request->validate(['lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180']]);

        if ($audit->status === 'scheduled') {
            $audit->update(['status' => 'in_progress', 'started_at' => now(), 'started_lat' => $loc['lat'] ?? null, 'started_lng' => $loc['lng'] ?? null]);
        } elseif ($audit->status === 'returned') {
            $audit->update(['status' => 'in_progress']);
        }

        return response()->json($this->present($audit));
    }

    /** Guarda respuestas en lote. Idempotente: si llega una versión más antigua que la guardada (client_updated_at) se ignora. */
    public function saveAnswers(Request $request, string $id): JsonResponse
    {
        $audit = $this->ownAudit($request, $id, editing: true);
        $data = $request->validate(['answers' => ['required', 'array', 'min:1', 'max:300'], 'answers.*.question_id' => ['required', 'string', 'max:40'], 'answers.*.comment' => ['nullable', 'string', 'max:1000'], 'answers.*.client_updated_at' => ['nullable', 'date']]);
        $questions = Scorer::questions($audit->structure);
        $errors = [];
        $skipped = [];

        DB::transaction(function () use ($data, $request, $audit, $questions, &$errors, &$skipped) {
            foreach ($request->input('answers') as $i => $a) {
                $q = $questions[$a['question_id']] ?? null;
                if (! $q) {
                    $errors["answers.$i.question_id"] = 'La pregunta no existe en esta auditoría.';
                    continue;
                }
                $hasValue = array_key_exists('value', $a);
                try {
                    $value = $hasValue ? AnswerValidator::normalize($q, $a['value']) : null;
                } catch (InvalidArgumentException $e) {
                    $errors["answers.$i.value"] = $e->getMessage();
                    continue;
                }
                $existing = FieldAuditAnswer::where('audit_id', $audit->id)->where('question_id', $q['id'])->first();
                $at = isset($a['client_updated_at']) ? \Carbon\Carbon::parse($a['client_updated_at']) : null;
                if ($existing && $at && $existing->client_updated_at && $existing->client_updated_at->gt($at)) {
                    $skipped[] = $q['id'];   // llegó una versión vieja desde un dispositivo sin conexión
                    continue;
                }
                $payload = ['comment' => array_key_exists('comment', $a) ? ($a['comment'] ?: null) : $existing?->comment, 'client_updated_at' => $at ?? now(), 'answered_by' => $request->user()->id];
                if ($hasValue) {
                    $payload['value'] = $value;
                } elseif (! $existing) {
                    $payload['value'] = null;
                }
                FieldAuditAnswer::updateOrCreate(['audit_id' => $audit->id, 'question_id' => $q['id']], $payload);
            }
        });
        if ($errors) {
            return response()->json(['message' => 'Hay respuestas no válidas.', 'errors' => $errors], 422);
        }

        return response()->json(['skipped' => $skipped, 'evaluation' => $this->evaluation($audit)]);
    }

    public function uploadEvidence(Request $request, string $id): JsonResponse
    {
        $audit = $this->ownAudit($request, $id, editing: true);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'], 'kind' => ['nullable', 'in:photo,signature'], 'question_id' => ['nullable', 'string', 'max:40'],
            'caption' => ['nullable', 'string', 'max:200'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'taken_at' => ['nullable', 'date'], 'client_id' => ['nullable', 'string', 'max:64'],
        ]);
        if (! empty($data['question_id'])) {
            abort_unless(isset(Scorer::questions($audit->structure)[$data['question_id']]), 422, 'La pregunta no existe en esta auditoría.');
        }
        if (! empty($data['client_id']) && ($dup = FieldAuditEvidence::where('audit_id', $audit->id)->where('client_id', $data['client_id'])->first())) {
            return response()->json($this->evidenceRow($dup));   // reintento de una subida ya recibida
        }
        abort_if(FieldAuditEvidence::where('audit_id', $audit->id)->count() >= 200, 422, 'Se alcanzó el máximo de 200 imágenes por auditoría.');

        try {
            $stored = $this->evidence->store($audit, $request->file('file'), $data['kind'] ?? 'photo');
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $ev = FieldAuditEvidence::create($stored + [
            'audit_id' => $audit->id, 'question_id' => $data['question_id'] ?? null, 'kind' => $data['kind'] ?? 'photo', 'caption' => $data['caption'] ?? null,
            'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'taken_at' => $data['taken_at'] ?? now(),
            'client_id' => $data['client_id'] ?? null, 'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($this->evidenceRow($ev) + ['evaluation' => $this->evaluation($audit)], 201);
    }

    public function deleteEvidence(Request $request, string $id, string $evidenceId): JsonResponse
    {
        $audit = $this->ownAudit($request, $id, editing: true);
        $ev = FieldAuditEvidence::where('audit_id', $audit->id)->findOrFail($evidenceId);
        $this->evidence->delete($ev);
        $ev->delete();

        return response()->json(['evaluation' => $this->evaluation($audit)]);
    }

    /** Envía la auditoría: exige lo obligatorio, calcula el puntaje y genera los hallazgos de lo que falló. */
    public function submit(Request $request, string $id): JsonResponse
    {
        $audit = $this->ownAudit($request, $id, editing: true);
        $loc = $request->validate(['lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180']]);
        $eval = $this->evaluation($audit);
        if ($eval['missing']) {
            return response()->json(['message' => 'Faltan datos obligatorios para enviar la auditoría.', 'missing' => $eval['missing']], 422);
        }

        DB::transaction(function () use ($audit, $eval, $loc, $request) {
            $audit->update([
                'status' => 'submitted', 'submitted_at' => now(), 'submitted_lat' => $loc['lat'] ?? null, 'submitted_lng' => $loc['lng'] ?? null,
                'score' => $eval['score'], 'result' => $eval['result'], 'critical_failures' => $eval['critical_failures'], 'review_notes' => null,
            ]);
            $this->createFindings($audit, $eval, $request->user()->id);
        });

        return response()->json($this->present($audit));
    }

    // ───────────────────────────── revisión ─────────────────────────────

    public function approve(Request $request, string $id): JsonResponse
    {
        $audit = $this->reviewable($request, $id);
        $audit->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $request->input('notes')]);
        $this->notify($audit, 'audit_approved');

        return response()->json($this->present($audit));
    }

    public function sendBack(Request $request, string $id): JsonResponse
    {
        $request->validate(['notes' => ['required', 'string', 'max:1000']]);
        $audit = $this->reviewable($request, $id);
        $audit->update(['status' => 'returned', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $request->input('notes')]);
        $this->notify($audit, 'audit_returned', ['notes' => $request->input('notes')]);

        return response()->json($this->present($audit));
    }

    /** Avisa al auditor por correo; un fallo de correo nunca debe romper la operación. */
    private function notify(FieldAudit $audit, string $template, array $extra = []): void
    {
        try {
            $auditor = \App\Models\User::find($audit->auditor_id);
            $auditor && app(\App\Support\Mail\MailService::class)->sendToUser($auditor, $template, array_filter($extra) + ['title' => $audit->title, 'url' => \App\Support\Mail\MailService::link($template === 'audit_assigned' || $template === 'audit_returned' ? 'my-audits/'.$audit->id : 'field-audits/'.$audit->id)]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ───────────────────────────── apoyo ─────────────────────────────

    /** Auditorías que el usuario puede ver: su alcance de organización y, si no gestiona, solo las propias. */
    private function visible(Request $request)
    {
        $user = $request->user();
        $profile = $request->header('X-Profile');
        $allowed = $this->access->allowedOrganizationIds($user, 'auditorias.ver', $profile);
        $manages = $this->access->can($user, 'auditorias.crear', $profile) || $this->access->can($user, 'auditorias.aprobar', $profile);

        return FieldAudit::where('tenant_id', $user->tenant_id)
            ->when($allowed !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('organization_id', $allowed)->orWhere('auditor_id', $user->id)))
            ->when(! $manages, fn ($q) => $q->where('auditor_id', $user->id));
    }

    private function findVisible(Request $request, string $id): FieldAudit
    {
        return $this->visible($request)->findOrFail($id);
    }

    private function ownAudit(Request $request, string $id, bool $editing = false): FieldAudit
    {
        $audit = FieldAudit::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        abort_unless($audit->auditor_id === $request->user()->id && $this->access->can($request->user(), 'auditorias.ejecutar', $request->header('X-Profile')), 403, 'Solo el auditor asignado puede ejecutar esta auditoría.');
        abort_if($editing && $audit->status !== 'in_progress', 409, $audit->status === 'scheduled' ? 'Inicie la auditoría antes de responder.' : 'Esta auditoría no admite cambios.');

        return $audit;
    }

    /** Aprobar o devolver: sobre auditorías enviadas, con alcance y nunca el propio auditor (separación de funciones). */
    private function reviewable(Request $request, string $id): FieldAudit
    {
        $audit = $this->findVisible($request, $id);
        abort_unless($this->access->canOnOrganization($request->user(), 'auditorias.aprobar', $audit->organization_id, $request->header('X-Profile')), 403, 'Fuera de su alcance.');
        abort_if($audit->auditor_id === $request->user()->id, 403, 'Quien ejecutó la auditoría no puede revisarla.');
        abort_unless($audit->status === 'submitted', 409, 'La auditoría no está pendiente de revisión.');

        return $audit;
    }

    private function checkRelations(string $tenant, array $d): void
    {
        $org = $d['organization_id'];
        abort_unless(DB::table('core.organizations')->where('tenant_id', $tenant)->where('id', $org)->whereNull('deleted_at')->exists(), 422, 'La organización no existe.');
        if (! empty($d['branch_id'])) {
            abort_unless(DB::table('core.branches')->where('tenant_id', $tenant)->where('organization_id', $org)->where('id', $d['branch_id'])->whereNull('deleted_at')->exists(), 422, 'La sucursal no pertenece a esa organización.');
        }
        if (! empty($d['project_id'])) {
            abort_unless(DB::table('master.projects')->where('tenant_id', $tenant)->where('organization_id', $org)->where('id', $d['project_id'])->whereNull('deleted_at')->exists(), 422, 'El proyecto no pertenece a esa organización.');
        }
    }

    /** El auditor debe ser un usuario activo del tenant con permiso de ejecutar y alcance sobre la organización. */
    private function checkAuditor(string $tenant, string $auditorId, string $orgId): void
    {
        $u = User::where('tenant_id', $tenant)->where('status', 'active')->find($auditorId);
        abort_unless($u, 422, 'El auditor no existe o no está activo.');
        abort_unless($this->access->canOnOrganization($u, 'auditorias.ejecutar', $orgId), 422, 'Ese usuario no puede ejecutar auditorías en esta organización.');
    }

    private function evaluation(FieldAudit $audit): array
    {
        $answers = FieldAuditAnswer::where('audit_id', $audit->id)->get()->mapWithKeys(fn ($a) => [$a->question_id => ['value' => $a->value, 'comment' => $a->comment]])->all();
        $counts = [];
        foreach (FieldAuditEvidence::where('audit_id', $audit->id)->whereNotNull('question_id')->get(['question_id', 'kind']) as $e) {
            $counts[$e->question_id][$e->kind] = ($counts[$e->question_id][$e->kind] ?? 0) + 1;
        }

        return Scorer::evaluate($audit->structure, $answers, $counts, (bool) $audit->scoring_enabled, $audit->pass_threshold);
    }

    private function createFindings(FieldAudit $audit, array $eval, string $userId): void
    {
        $questions = Scorer::questions($audit->structure);
        $answers = FieldAuditAnswer::where('audit_id', $audit->id)->get()->keyBy('question_id');
        foreach ($eval['findings'] as $qid) {
            $q = $questions[$qid];
            FieldAuditFinding::firstOrCreate(['audit_id' => $audit->id, 'question_id' => $qid, 'source' => 'auto'], [
                'tenant_id' => $audit->tenant_id, 'section_title' => $q['_section_title'], 'question_text' => $q['text'], 'severity' => $q['severity'],
                'description' => 'Incumplimiento: '.$q['text'].(($c = $answers[$qid]->comment ?? null) ? " — {$c}" : ''), 'status' => 'open', 'created_by' => $userId,
            ]);
        }
    }

    private function evidenceRow(FieldAuditEvidence $e): array
    {
        return ['id' => $e->id, 'question_id' => $e->question_id, 'kind' => $e->kind, 'caption' => $e->caption, 'latitude' => $e->latitude, 'longitude' => $e->longitude,
            'taken_at' => $e->taken_at, 'url' => $this->evidence->url($e), 'client_id' => $e->client_id];
    }

    private function present(FieldAudit $a): array
    {
        $orgs = DB::table('core.organizations')->where('id', $a->organization_id)->value('legal_name');
        $branch = $a->branch_id ? DB::table('core.branches')->where('id', $a->branch_id)->value('name') : null;
        $project = $a->project_id ? DB::table('master.projects')->where('id', $a->project_id)->value('name') : null;
        $auditor = DB::table('security.users')->where('id', $a->auditor_id)->value('email');
        $reviewer = $a->reviewed_by ? DB::table('security.users')->where('id', $a->reviewed_by)->value('email') : null;

        return $a->makeHidden('structure')->toArray() + [
            'organization' => $orgs, 'branch' => $branch, 'project' => $project, 'auditor' => $auditor, 'reviewer' => $reviewer,
            'structure' => $a->structure,
            'answers' => FieldAuditAnswer::where('audit_id', $a->id)->get()->mapWithKeys(fn ($r) => [$r->question_id => ['value' => $r->value, 'comment' => $r->comment]]),
            'evidences' => FieldAuditEvidence::where('audit_id', $a->id)->orderBy('created_at')->get()->map(fn ($e) => $this->evidenceRow($e))->values(),
            'findings' => FieldAuditFinding::where('audit_id', $a->id)->orderBy('created_at')->get(),
            'evaluation' => $this->evaluation($a),
        ];
    }
}
