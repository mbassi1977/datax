<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FieldAudit;
use App\Models\FieldAuditAnswer;
use App\Models\FieldAuditEvidence;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sincronización para la app de terreno (funciona sin conexión).
 *
 *  1. pull: el dispositivo descarga sus levantamientos pendientes con plantilla, respuestas y fotos ya subidas.
 *  2. Offline: guarda respuestas/fotos localmente y registra operaciones con un op_id (UUID generado en el dispositivo).
 *  3. push: al volver la red envía las operaciones EN ORDEN (start → answers → … ). El servidor las aplica con las mismas
 *     reglas de la API normal y recuerda cada op_id: reenviar la misma operación devuelve el mismo resultado sin duplicar.
 *  4. Las fotos suben con POST /audits/{id}/evidence usando client_id (también idempotente).
 *  5. Al final, una operación `submit` envía el levantamiento (exige lo obligatorio y que las fotos ya estén arriba).
 *
 * Cada operación se resuelve sola: `ok` (aplicada), `rejected` (el servidor la rechaza definitivamente: no reintentar,
 * avisar al usuario) o `retry` (falla transitoria: reintentar luego). Una rechazada no detiene las demás.
 */
class FieldSyncController extends Controller
{
    private const TYPES = ['start', 'answers', 'submit', 'branch'];

    public function __construct(private AccessService $access, private FieldAuditController $audits, private BranchController $branches) {}

    public function pull(Request $request): JsonResponse
    {
        $request->validate(['since' => ['nullable', 'date']]);
        $user = $request->user();
        abort_unless($this->access->can($user, 'auditorias.ejecutar', $request->header('X-Profile')), 403, 'Su perfil no ejecuta levantamientos.');

        $mine = FieldAudit::where('tenant_id', $user->tenant_id)->where('auditor_id', $user->id)->whereIn('status', ['scheduled', 'in_progress', 'returned']);
        $activeIds = (clone $mine)->pluck('id');
        $changed = $mine->when($request->filled('since'), fn ($q) => $q->where('updated_at', '>', $request->query('since')))->orderBy('scheduled_for')->get();

        $branches = DB::table('core.branches')->whereIn('id', $changed->pluck('branch_id')->filter())->get(['id', 'name', 'address'])->keyBy('id');
        $orgs = DB::table('core.organizations')->whereIn('id', $changed->pluck('organization_id'))->pluck('legal_name', 'id');

        $profile = $request->header('X-Profile');
        $seeOrgs = $this->access->allowedOrganizationIds($user, 'sucursales.ver', $profile);     // null = global
        $runOrgs = $this->access->allowedOrganizationIds($user, 'auditorias.ejecutar', $profile);
        $canCreate = $this->access->can($user, 'sucursales.crear', $profile);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            // Lo que el perfil permite hacer en terreno: leer sucursales existentes y/o crear nuevas.
            'can' => ['read_branches' => $this->access->can($user, 'sucursales.ver', $profile), 'create_branches' => $canCreate],
            'branches' => $this->access->can($user, 'sucursales.ver', $profile)
                ? DB::table('core.branches')->where('tenant_id', $user->tenant_id)->whereNull('deleted_at')->when($seeOrgs !== null, fn ($q) => $q->whereIn('organization_id', $seeOrgs))
                    ->orderBy('name')->limit(5000)->get(['id', 'organization_id', 'name', 'address', 'commune'])
                : [],
            // Levantamientos activos donde el colector puede abrir un local por su cuenta (p. ej. uno recién creado).
            'open_surveys' => \App\Models\FieldSurvey::where('tenant_id', $user->tenant_id)->where('status', 'active')->when($runOrgs !== null, fn ($q) => $q->whereIn('organization_id', $runOrgs))
                ->get(['id', 'name', 'organization_id']),
            'active_ids' => $activeIds,                         // lo que ya no está aquí (enviado, cancelado) puede borrarse del dispositivo
            'audits' => $changed->map(fn ($a) => [
                'id' => $a->id, 'survey_id' => $a->survey_id, 'title' => $a->title, 'status' => $a->status, 'scheduled_for' => $a->scheduled_for?->toDateString(), 'due_on' => $a->due_on?->toDateString(),
                'organization' => $orgs[$a->organization_id] ?? null, 'branch' => ($b = $branches[$a->branch_id] ?? null) ? ['id' => $b->id, 'name' => $b->name, 'address' => $b->address ?? null] : null,
                'review_notes' => $a->review_notes, 'scoring_enabled' => (bool) $a->scoring_enabled, 'structure' => $a->structure, 'updated_at' => $a->updated_at?->toIso8601String(),
                'answers' => FieldAuditAnswer::where('audit_id', $a->id)->get()->mapWithKeys(fn ($r) => [$r->question_id => ['value' => $r->value, 'comment' => $r->comment, 'client_updated_at' => $r->client_updated_at?->toIso8601String()]]),
                'evidence_client_ids' => FieldAuditEvidence::where('audit_id', $a->id)->whereNotNull('client_id')->pluck('client_id'),
            ])->values(),
        ]);
    }

    public function push(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'ops' => ['required', 'array', 'min:1', 'max:100'], 'ops.*.op_id' => ['required', 'string', 'max:64'], 'ops.*.type' => ['required', 'in:'.implode(',', self::TYPES)],
            'ops.*.audit_id' => ['required_unless:ops.*.type,branch', 'nullable', 'uuid'], 'ops.*.device_at' => ['nullable', 'date'],
        ]);
        abort_unless($this->access->can($user, 'auditorias.ejecutar', $request->header('X-Profile')), 403, 'Su perfil no ejecuta levantamientos.');

        $results = [];
        foreach ($request->input('ops') as $op) {
            $results[] = ['op_id' => $op['op_id']] + $this->apply($request, $op);
        }

        return response()->json(['server_time' => now()->toIso8601String(), 'results' => $results]);
    }

    private function apply(Request $request, array $op): array
    {
        $user = $request->user();
        if ($seen = DB::table('fieldaudit.sync_ops')->where('user_id', $user->id)->where('op_id', $op['op_id'])->first()) {
            return ['outcome' => $seen->outcome, 'status' => (int) $seen->http_status, 'result' => json_decode($seen->result ?? 'null', true), 'duplicate' => true];
        }

        if ($op['type'] === 'branch') {
            return $this->finish($user, $op, null, ...$this->createBranch($request, $op));
        }
        $audit = FieldAudit::where('tenant_id', $user->tenant_id)->where('auditor_id', $user->id)->find($op['audit_id']);
        $status = 200;
        $body = null;
        if (! $audit) {
            [$status, $body] = [404, ['message' => 'El levantamiento no existe o ya no es suyo.']];
        } elseif ($op['type'] === 'start' && in_array($audit->status, ['in_progress', 'submitted', 'approved'], true)) {
            $body = ['already' => $audit->status];                                   // reintento o ya avanzado: nada que hacer
        } elseif ($op['type'] === 'submit' && in_array($audit->status, ['submitted', 'approved'], true)) {
            $body = ['already' => $audit->status];
        } else {
            [$status, $body] = $this->dispatch($request, $op, $audit);
        }

        return $this->finish($user, $op, $audit, $status, $body);
    }

    private function finish($user, array $op, ?FieldAudit $audit, int $status, $body): array
    {
        if ($status >= 500) {
            return ['outcome' => 'retry', 'status' => $status, 'result' => $body];     // no se registra: se reintenta
        }
        $outcome = $status < 300 ? 'ok' : 'rejected';
        DB::table('fieldaudit.sync_ops')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'op_id' => $op['op_id'], 'audit_id' => $audit?->id, 'type' => $op['type'], 'outcome' => $outcome,
            'http_status' => $status, 'result' => json_encode($body), 'device_at' => $op['device_at'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['outcome' => $outcome, 'status' => $status, 'result' => $body];
    }

    /** Aplica la operación con las mismas reglas y validaciones de la API normal. */
    private function dispatch(Request $parent, array $op, FieldAudit $audit): array
    {
        $payload = match ($op['type']) {
            'answers' => ['answers' => $op['answers'] ?? []],
            default => ['lat' => $op['lat'] ?? null, 'lng' => $op['lng'] ?? null],
        };
        $sub = Request::create('/', 'POST', $payload);
        $sub->setUserResolver(fn () => $parent->user());
        $sub->headers->set('X-Profile', (string) $parent->header('X-Profile'));

        try {
            $res = match ($op['type']) {
                'start' => $this->audits->start($sub, $audit->id),
                'answers' => $this->audits->saveAnswers($sub, $audit->id),
                'submit' => $this->audits->submit($sub, $audit->id),
            };

            return [$res->getStatusCode(), $op['type'] === 'answers' ? $res->getData(true) : ['status' => $res->getData(true)['status'] ?? null]];
        } catch (ValidationException $e) {
            return [422, ['message' => 'Datos no válidos.', 'errors' => $e->errors()]];
        } catch (HttpException $e) {
            return [$e->getStatusCode(), ['message' => $e->getMessage()]];
        }
    }

    /**
     * Crea una sucursal hallada en terreno, con el mismo permiso y alcance que la API de sucursales (sucursales.crear).
     * Con survey_id, además abre el local en ese levantamiento para el propio colector (devuelve audit_id).
     */
    private function createBranch(Request $parent, array $op): array
    {
        $payload = array_intersect_key($op, array_flip(['organization_id', 'name', 'address', 'commune']));
        $sub = Request::create('/', 'POST', $payload);
        $sub->setUserResolver(fn () => $parent->user());
        $sub->headers->set('X-Profile', (string) $parent->header('X-Profile'));

        try {
            return DB::transaction(function () use ($sub, $op, $parent) {      // si no puede abrirla en el levantamiento, tampoco se crea la sucursal
                $branch = $this->branches->store($sub)->getData(true);
                $body = ['branch' => ['id' => $branch['id'], 'organization_id' => $branch['organization_id'], 'name' => $branch['name']]];
                if (! empty($op['survey_id'])) {
                    $body['audit_id'] = $this->openOwn($parent, $op['survey_id'], $branch['id']);
                }

                return [201, $body];
            });
        } catch (ValidationException $e) {
            return [422, ['message' => 'Datos no válidos.', 'errors' => $e->errors()]];
        } catch (HttpException $e) {
            return [$e->getStatusCode(), ['message' => $e->getMessage()]];
        }
    }

    private function openOwn(Request $request, string $surveyId, string $branchId): string
    {
        $user = $request->user();
        $s = \App\Models\FieldSurvey::where('tenant_id', $user->tenant_id)->where('status', 'active')->find($surveyId);
        abort_unless($s && $this->access->canOnOrganization($user, 'auditorias.ejecutar', $s->organization_id, $request->header('X-Profile')), 403, 'No puede abrir locales en este levantamiento.');
        $t = \App\Models\FieldAuditTemplate::where('tenant_id', $user->tenant_id)->findOrFail($s->template_id);
        $name = DB::table('core.branches')->where('id', $branchId)->value('name');

        return FieldAudit::create([
            'tenant_id' => $user->tenant_id, 'survey_id' => $s->id, 'template_id' => $t->id, 'template_name' => $t->name, 'template_version' => $t->version, 'pass_threshold' => $t->pass_threshold,
            'scoring_enabled' => (bool) $t->scoring_enabled, 'structure' => $t->structure, 'title' => "{$s->name} · {$name}", 'organization_id' => $s->organization_id, 'branch_id' => $branchId,
            'auditor_id' => $user->id, 'scheduled_for' => now()->toDateString(), 'status' => 'scheduled', 'created_by' => $user->id,
        ])->id;
    }
}
