<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Branch;

class BranchController extends ScopedResourceController
{
    protected function model(): string { return Branch::class; }
    protected function prefix(): string { return 'sucursales'; }

    protected function rules(string $tenantId, ?string $orgId, ?string $ignoreId): array
    {
        $r = $ignoreId ? 'sometimes' : 'required';

        return [
            'name' => [$r, 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'commune' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
