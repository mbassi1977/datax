<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;

class ProjectController extends ScopedResourceController
{
    protected function model(): string { return Project::class; }
    protected function prefix(): string { return 'proyectos'; }
    protected function searchable(): array { return ['name', 'code']; }

    protected function rules(string $tenantId, ?string $orgId, ?string $ignoreId): array
    {
        $r = $ignoreId ? 'sometimes' : 'required';

        return [
            'name' => [$r, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30', $this->uniqueInTenant('master.projects', 'code', $tenantId, $ignoreId)],
            'description' => ['nullable', 'string'],
            'cost_center_id' => ['nullable', 'uuid', $this->existsInTenant('core.cost_centers', $tenantId, $orgId)],
            'manager_id' => ['nullable', 'uuid', $this->existsInTenant('master.people', $tenantId)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['sometimes', 'in:planned,active,paused,closed'],
        ];
    }
}
