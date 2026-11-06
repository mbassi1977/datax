<?php

namespace App\Imports;

use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Model;

class CostCenterTarget extends OrgScopedTarget
{
    public function key(): string { return 'cost_centers'; }
    public function label(): string { return 'Centros de costo'; }
    public function permission(): string { return 'centros_costo'; }

    public function fields(): array
    {
        return [
            'organization' => $this->orgField(),
            'code' => ['label' => 'Código', 'required' => true, 'synonyms' => ['codigo cc', 'cc', 'centro de costo', 'codigo centro de costo'], 'example' => 'CC-100'],
            'name' => ['label' => 'Nombre', 'required' => true, 'synonyms' => ['nombre centro de costo', 'descripcion'], 'example' => 'Operaciones Santiago'],
            'area' => ['label' => 'Área (nombre)', 'synonyms' => ['area', 'unidad'], 'example' => 'Operaciones'],
            'status' => ['label' => 'Estado', 'synonyms' => ['activo', 'vigente'], 'example' => 'Activo'],
        ];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        $this->resolveOrg($raw, $ctx, $data, $errors);
        $this->name($raw, $data, $errors);
        if ($c = $this->required($raw, 'code', $errors)) {
            mb_strlen($c) > 30 ? $errors['code'] = 'Máximo 30 caracteres.' : $data['code'] = $c;
        }
        $this->status($raw, $data, $errors);
        if (isset($data['organization_id']) && ($a = trim((string) ($raw['area'] ?? ''))) !== '') {
            $row = $this->lookup('core.areas', $ctx, $data['organization_id'], 'name', $a);
            $row ? $data['area_id'] = $row->id : $errors['area'] = "No existe el área «{$a}» en esa organización.";
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        return CostCenter::where('tenant_id', $ctx->tenantId())->where('organization_id', $data['organization_id'] ?? null)->whereRaw('lower(code) = ?', [mb_strtolower($data['code'] ?? '')])->first();
    }

    public function dedupeKey(array $data): ?string { return isset($data['code'], $data['organization_id']) ? $data['organization_id'].'|'.Cast::norm($data['code']) : null; }

    public function create(ImportContext $ctx, array $data): Model
    {
        return CostCenter::create($data + ['tenant_id' => $ctx->tenantId(), 'created_by' => $ctx->user->id]);
    }
}
