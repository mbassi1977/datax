<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Person;

class PersonController extends ScopedResourceController
{
    protected function model(): string { return Person::class; }
    protected function prefix(): string { return 'personas'; }
    protected function searchable(): array { return ['first_name', 'last_name', 'second_last_name', 'rut', 'email']; }
    protected function orgRequired(): bool { return false; } // una persona puede existir sin empresa

    protected function rules(string $tenantId, ?string $orgId, ?string $ignoreId): array
    {
        $r = $ignoreId ? 'sometimes' : 'required';

        return [
            'first_name' => [$r, 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'second_last_name' => ['nullable', 'string', 'max:255'],
            'rut' => ['nullable', 'string', 'max:20', $this->uniqueInTenant('master.people', 'rut', $tenantId, $ignoreId)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'sex' => ['nullable', 'string', 'max:20'],
            'nationality' => ['nullable', 'string'],
            'job_title' => ['nullable', 'string'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'in:trabajador,consultor,relator,evaluador,participante,cliente,medico,tutor,administrador'],
            'area_id' => ['nullable', 'uuid', $this->existsInTenant('core.areas', $tenantId, $orgId)],
            'supervisor_id' => ['nullable', 'uuid', $this->existsInTenant('master.people', $tenantId)],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
