<?php

namespace App\Http\Controllers\Api\V1;

use App\FieldAudit\TemplateValidator;
use App\Http\Controllers\Controller;
use App\Models\FieldAuditTemplate;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plantillas de auditoría (secciones y preguntas). Borrador → publicada → archivada.
 * Editar una plantilla publicada sube su versión; las auditorías ya creadas conservan la copia con la que nacieron.
 * Son configuración del tenant: escribir exige alcance global.
 */
class FieldAuditTemplateController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $q = FieldAuditTemplate::where('tenant_id', $request->user()->tenant_id)->orderBy('name')
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->query('status')))
            ->when($request->filled('q'), fn ($w) => $w->where('name', 'ilike', '%'.$request->query('q').'%'));

        return response()->json($q->get()->map(fn ($t) => $this->summary($t)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $t = $this->find($request, $id);

        return response()->json($this->summary($t) + ['structure' => $t->structure]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireGlobal($request, 'plantillas_auditoria.crear');
        $data = $this->validated($request);
        $t = FieldAuditTemplate::create([
            'tenant_id' => $request->user()->tenant_id, 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'pass_threshold' => $data['pass_threshold'] ?? 80, 'status' => 'draft', 'version' => 1, 'created_by' => $request->user()->id,
            'structure' => TemplateValidator::normalize($request->input('structure', ['sections' => []]), false),
        ]);

        return response()->json($this->summary($t) + ['structure' => $t->structure], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'plantillas_auditoria.editar');
        $t = $this->find($request, $id);
        abort_if($t->status === 'archived', 409, 'La plantilla está archivada. Restáurela para editarla.');
        $data = $this->validated($request, $t->id, partial: true);

        $published = $t->status === 'published';
        $changes = collect($data)->only(['name', 'description', 'pass_threshold'])->all();
        if ($request->has('structure')) {
            $changes['structure'] = TemplateValidator::normalize($request->input('structure'), $published);   // publicada: siempre completa
        }
        // Cambia el contenido de una plantilla en uso: nueva versión (las auditorías existentes no cambian).
        if ($published && (isset($changes['structure']) && $changes['structure'] !== $t->structure || isset($changes['pass_threshold']) && $changes['pass_threshold'] != $t->pass_threshold)) {
            $changes['version'] = $t->version + 1;
        }
        $t->update($changes + ['updated_by' => $request->user()->id]);

        return response()->json($this->summary($t) + ['structure' => $t->structure]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'plantillas_auditoria.editar');
        $t = $this->find($request, $id);
        abort_if($t->status === 'published', 409, 'La plantilla ya está publicada.');
        $structure = TemplateValidator::normalize($t->structure, true);
        $t->update(['status' => 'published', 'structure' => $structure, 'updated_by' => $request->user()->id]);

        return response()->json($this->summary($t) + ['structure' => $t->structure]);
    }

    /** Archivar la saca de las asignaciones; restaurar la devuelve a borrador para revisarla antes de volver a publicarla. */
    public function archive(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'plantillas_auditoria.editar');
        $t = $this->find($request, $id);
        $t->update(['status' => 'archived', 'updated_by' => $request->user()->id]);

        return response()->json($this->summary($t));
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'plantillas_auditoria.editar');
        $t = $this->find($request, $id);
        abort_unless($t->status === 'archived', 409, 'La plantilla no está archivada.');
        $t->update(['status' => 'draft', 'updated_by' => $request->user()->id]);

        return response()->json($this->summary($t));
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'plantillas_auditoria.crear');
        $t = $this->find($request, $id);
        $base = $t->name.' (copia)';
        $name = $base;
        for ($i = 2; FieldAuditTemplate::where('tenant_id', $t->tenant_id)->where('name', $name)->exists(); $i++) {
            $name = "{$base} {$i}";
        }
        $copy = FieldAuditTemplate::create([
            'tenant_id' => $t->tenant_id, 'name' => $name, 'description' => $t->description, 'pass_threshold' => $t->pass_threshold,
            'status' => 'draft', 'version' => 1, 'created_by' => $request->user()->id,
            'structure' => TemplateValidator::normalize($this->withoutIds($t->structure), false),   // ids nuevos
        ]);

        return response()->json($this->summary($copy) + ['structure' => $copy->structure], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->requireGlobal($request, 'plantillas_auditoria.eliminar');
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
    }

    private function find(Request $request, string $id): FieldAuditTemplate
    {
        return FieldAuditTemplate::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function requireGlobal(Request $request, string $permission): void
    {
        abort_unless($this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile')) === null, 403, 'Administrar plantillas requiere alcance global.');
    }

    private function validated(Request $request, ?string $ignoreId = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:150', Rule::unique(config('database.default').'.fieldaudit.templates', 'name')->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'pass_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function summary(FieldAuditTemplate $t): array
    {
        $sections = $t->structure['sections'] ?? [];

        return ['id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'status' => $t->status, 'version' => $t->version, 'pass_threshold' => $t->pass_threshold,
            'sections_count' => count($sections), 'questions_count' => array_sum(array_map(fn ($s) => count($s['questions'] ?? []), $sections)), 'updated_at' => $t->updated_at];
    }

    private function withoutIds(array $structure): array
    {
        foreach ($structure['sections'] ?? [] as $si => $s) {
            unset($structure['sections'][$si]['id']);
            foreach ($s['questions'] ?? [] as $qi => $q) {
                unset($structure['sections'][$si]['questions'][$qi]['id']);
                $structure['sections'][$si]['questions'][$qi]['show_if'] = null;   // las condiciones apuntaban a ids antiguos
                foreach ($q['options'] ?? [] as $oi => $_) {
                    unset($structure['sections'][$si]['questions'][$qi]['options'][$oi]['id']);
                }
            }
        }

        return $structure;
    }
}
