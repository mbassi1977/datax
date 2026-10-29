<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $allowed = $this->access->allowedOrganizationIds($request->user(), 'organizaciones.ver', $request->header('X-Profile'));

        $query = Organization::where('tenant_id', $request->user()->tenant_id)->orderBy('legal_name');
        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        return response()->json($query->paginate(50));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->find($request, $id, 'organizaciones.ver'));
    }

    /** Crear organizaciones nuevas exige alcance global dentro del tenant. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($this->access->allowedOrganizationIds($user, 'organizaciones.crear', $request->header('X-Profile')) !== null) {
            abort(403, 'Crear organizaciones requiere alcance global.');
        }

        $data = $this->validated($request, $user->tenant_id);
        $organization = Organization::create($data + ['tenant_id' => $user->tenant_id, 'created_by' => $user->id]);

        return response()->json($organization, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $organization = $this->find($request, $id, 'organizaciones.editar');
        $organization->update($this->validated($request, $request->user()->tenant_id, $organization->id) + ['updated_by' => $request->user()->id]);

        return response()->json($organization);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id, 'organizaciones.eliminar')->delete();

        return response()->json(null, 204);
    }

    private function find(Request $request, string $id, string $permission): Organization
    {
        $organization = Organization::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        if (! $this->access->canOnOrganization($request->user(), $permission, $organization->id, $request->header('X-Profile'))) {
            abort(403, 'Fuera de su alcance.');
        }

        return $organization;
    }

    /** Las reglas unique/exists leen "a.b" como conexión.tabla: se antepone la conexión para tablas con esquema. */
    private function table(string $name): string
    {
        return config('database.default').'.core.'.$name;
    }

    private function validated(Request $request, string $tenantId, ?string $ignoreId = null): array
    {
        $partial = $ignoreId ? 'sometimes' : 'required';

        return $request->validate([
            'legal_name' => [$partial, 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'rut' => ['nullable', 'string', 'max:20', Rule::unique($this->table('organizations'), 'rut')->where('tenant_id', $tenantId)->ignore($ignoreId)],
            'type' => ['sometimes', Rule::in(Organization::TYPES)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'region' => ['nullable', 'string'],
            'commune' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'industry' => ['nullable', 'string'],
            'holding_id' => ['nullable', 'uuid', Rule::exists($this->table('holdings'), 'id')->where('tenant_id', $tenantId)],
        ]);
    }
}
