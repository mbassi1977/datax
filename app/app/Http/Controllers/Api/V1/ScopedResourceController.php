<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Access\AccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD de una entidad de negocio acotada por tenant y por alcance (organización).
 * Cada subclase declara modelo, prefijo de permisos y reglas de validación.
 */
abstract class ScopedResourceController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Prefijo de permisos: "personas" => personas.ver, personas.crear... */
    abstract protected function prefix(): string;

    /** Reglas de los campos propios. $orgId es la organización efectiva del registro. */
    abstract protected function rules(string $tenantId, ?string $orgId, ?string $ignoreId): array;

    /** Columnas de texto para el filtro ?q= */
    protected function searchable(): array
    {
        return ['name'];
    }

    protected function orgRequired(): bool
    {
        return true;
    }

    public function __construct(protected AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = $this->access->allowedOrganizationIds($user, $this->prefix().'.ver', $request->header('X-Profile'));

        $query = $this->model()::where('tenant_id', $user->tenant_id);
        if ($allowed !== null) {
            $query->whereIn('organization_id', $allowed); // registros sin organización: solo alcance global
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->query('organization_id'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $query->where(fn ($q) => collect($this->searchable())->each(fn ($c) => $q->orWhere($c, 'ilike', $term)));
        }

        return response()->json($query->latest('created_at')->paginate(50));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->find($request, $id, 'ver'));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validate($request, $user->tenant_id, $request->input('organization_id'), null);
        $this->authorizeOrganization($request, 'crear', $data['organization_id'] ?? null);

        $record = $this->model()::create($data + ['tenant_id' => $user->tenant_id, 'created_by' => $user->id]);

        return response()->json($record, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $record = $this->find($request, $id, 'editar');
        $orgId = $request->input('organization_id', $record->organization_id);
        $data = $this->validate($request, $request->user()->tenant_id, $orgId, $record->getKey());

        if (array_key_exists('organization_id', $data) && $data['organization_id'] !== $record->organization_id) {
            $this->authorizeOrganization($request, 'editar', $data['organization_id']); // también sobre el destino
        }
        $record->update($data + ['updated_by' => $request->user()->id]);

        return response()->json($record);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id, 'eliminar')->delete();

        return response()->json(null, 204);
    }

    protected function find(Request $request, string $id, string $action): Model
    {
        $record = $this->model()::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOrganization($request, $action, $record->organization_id);

        return $record;
    }

    /** Sin organización (null) solo se permite con alcance global. */
    protected function authorizeOrganization(Request $request, string $action, ?string $organizationId): void
    {
        $permission = $this->prefix().'.'.$action;
        $allowed = $this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile'));

        if ($allowed !== null && ($organizationId === null || ! in_array($organizationId, $allowed, true))) {
            abort(403, 'Fuera de su alcance.');
        }
    }

    private function validate(Request $request, string $tenantId, ?string $orgId, ?string $ignoreId): array
    {
        $partial = $ignoreId ? 'sometimes' : 'required';
        $orgRule = $this->orgRequired()
            ? [$partial, 'uuid', $this->existsInTenant('core.organizations', $tenantId)]
            : ['nullable', 'uuid', $this->existsInTenant('core.organizations', $tenantId)];

        return $request->validate(['organization_id' => $orgRule] + $this->rules($tenantId, $orgId, $ignoreId));
    }

    /** Las reglas unique/exists leen "a.b" como conexión.tabla: se antepone la conexión (tablas con esquema). */
    protected function tableRef(string $qualified): string
    {
        return config('database.default').'.'.$qualified;
    }

    protected function existsInTenant(string $qualified, string $tenantId, ?string $orgId = null)
    {
        $rule = Rule::exists($this->tableRef($qualified), 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return $orgId ? $rule->where('organization_id', $orgId) : $rule;
    }

    protected function uniqueInTenant(string $qualified, string $column, string $tenantId, ?string $ignoreId, ?string $orgId = null)
    {
        $rule = Rule::unique($this->tableRef($qualified), $column)->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($ignoreId);

        return $orgId ? $rule->where('organization_id', $orgId) : $rule;
    }
}
