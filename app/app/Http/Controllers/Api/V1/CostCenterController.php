<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CostCenter;

class CostCenterController extends ScopedResourceController
{
    protected function model(): string { return CostCenter::class; }
    protected function prefix(): string { return 'centros_costo'; }
    protected function searchable(): array { return ['name', 'code']; }

    protected function rules(string $tenantId, ?string $orgId, ?string $ignoreId): array
    {
        $r = $ignoreId ? 'sometimes' : 'required';

        return [
            'code' => [$r, 'string', 'max:30', $this->uniqueInTenant('core.cost_centers', 'code', $tenantId, $ignoreId, $orgId)],
            'name' => [$r, 'string', 'max:255'],
            'area_id' => ['nullable', 'uuid', $this->existsInTenant('core.areas', $tenantId, $orgId)],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
