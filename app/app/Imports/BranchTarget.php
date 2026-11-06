<?php

namespace App\Imports;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;

class BranchTarget extends OrgScopedTarget
{
    public function key(): string { return 'branches'; }
    public function label(): string { return 'Sucursales'; }
    public function permission(): string { return 'sucursales'; }

    public function fields(): array
    {
        return [
            'organization' => $this->orgField(),
            'name' => ['label' => 'Nombre de la sucursal', 'required' => true, 'synonyms' => ['sucursal', 'local', 'tienda', 'nombre'], 'example' => 'Casa Matriz'],
            'address' => ['label' => 'Dirección', 'synonyms' => ['domicilio'], 'example' => 'Av. Principal 123'],
            'commune' => ['label' => 'Comuna', 'synonyms' => [], 'example' => 'Santiago'],
            'status' => ['label' => 'Estado', 'synonyms' => ['activo', 'vigente'], 'example' => 'Activo'],
        ];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        $this->resolveOrg($raw, $ctx, $data, $errors);
        $this->name($raw, $data, $errors);
        $this->text($raw, 'address', $data, 255, $errors);
        $this->text($raw, 'commune', $data, 255, $errors);
        $this->status($raw, $data, $errors);

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        return Branch::where('tenant_id', $ctx->tenantId())->where('organization_id', $data['organization_id'] ?? null)->whereRaw('lower(name) = ?', [mb_strtolower($data['name'] ?? '')])->first();
    }

    public function dedupeKey(array $data): ?string { return isset($data['name'], $data['organization_id']) ? $data['organization_id'].'|'.Cast::norm($data['name']) : null; }

    public function create(ImportContext $ctx, array $data): Model
    {
        return Branch::create($data + ['tenant_id' => $ctx->tenantId(), 'created_by' => $ctx->user->id]);
    }
}
