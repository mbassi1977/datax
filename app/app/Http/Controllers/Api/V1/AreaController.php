<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Area;
use Illuminate\Validation\Rule;

class AreaController extends ScopedResourceController
{
    protected function model(): string { return Area::class; }
    protected function prefix(): string { return 'areas'; }

    protected function rules(string $tenantId, ?string $orgId, ?string $ignoreId): array
    {
        $r = $ignoreId ? 'sometimes' : 'required';

        return [
            'name' => [$r, 'string', 'max:255'],
            'branch_id' => ['nullable', 'uuid', $this->existsInTenant('core.branches', $tenantId, $orgId)],
            'parent_id' => ['nullable', 'uuid', $this->existsInTenant('core.areas', $tenantId, $orgId), $ignoreId ? Rule::notIn([$ignoreId]) : 'nullable'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
