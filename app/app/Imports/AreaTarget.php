<?php

namespace App\Imports;

use App\Models\Area;
use Illuminate\Database\Eloquent\Model;

/** Áreas jerárquicas: la columna «Área superior» apunta a otra área de la misma organización (puede venir antes en el mismo archivo). */
class AreaTarget extends OrgScopedTarget
{
    public function key(): string { return 'areas'; }
    public function label(): string { return 'Áreas'; }
    public function permission(): string { return 'areas'; }

    public function fields(): array
    {
        return [
            'organization' => $this->orgField(),
            'name' => ['label' => 'Nombre del área', 'required' => true, 'synonyms' => ['area', 'unidad', 'gerencia', 'departamento', 'nombre'], 'example' => 'Operaciones'],
            'parent' => ['label' => 'Área superior (nombre)', 'synonyms' => ['area superior', 'area padre', 'depende de', 'padre'], 'example' => 'Gerencia General'],
            'branch' => ['label' => 'Sucursal (nombre)', 'synonyms' => ['sucursal', 'local'], 'example' => 'Casa Matriz'],
            'status' => ['label' => 'Estado', 'synonyms' => ['activo', 'vigente'], 'example' => 'Activo'],
        ];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        $this->resolveOrg($raw, $ctx, $data, $errors);
        $this->name($raw, $data, $errors);
        $this->status($raw, $data, $errors);
        $org = $data['organization_id'] ?? null;

        if ($org && ($b = trim((string) ($raw['branch'] ?? ''))) !== '') {
            $row = $this->lookup('core.branches', $ctx, $org, 'name', $b);
            $row ? $data['branch_id'] = $row->id : $errors['branch'] = "No existe la sucursal «{$b}» en esa organización.";
        }
        if ($org && ($p = trim((string) ($raw['parent'] ?? ''))) !== '') {
            $row = $this->lookup('core.areas', $ctx, $org, 'name', $p);
            if ($row) {
                $data['parent_id'] = $row->id;
            } elseif (isset($ctx->options['_seen_areas'][$org.'|'.Cast::norm($p)])) {
                $data['parent_pending'] = $p;   // se crea antes en este mismo archivo (simulación)
            } else {
                $errors['parent'] = "No existe el área superior «{$p}». Debe estar antes en el archivo o ya creada.";
            }
        }
        if ($org && isset($data['name'])) {
            $ctx->options['_seen_areas'][$org.'|'.Cast::norm($data['name'])] = true;
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        return Area::where('tenant_id', $ctx->tenantId())->where('organization_id', $data['organization_id'] ?? null)
            ->whereRaw('lower(name) = ?', [mb_strtolower($data['name'] ?? '')])->first();
    }

    public function dedupeKey(array $data): ?string { return isset($data['name'], $data['organization_id']) ? $data['organization_id'].'|'.Cast::norm($data['name']) : null; }

    public function create(ImportContext $ctx, array $data): Model
    {
        unset($data['parent_pending']);

        return Area::create($data + ['tenant_id' => $ctx->tenantId(), 'created_by' => $ctx->user->id]);
    }

    public function update(ImportContext $ctx, Model $existing, array $data): Model
    {
        unset($data['parent_pending']);
        if (($data['parent_id'] ?? null) === $existing->id) {
            unset($data['parent_id']);
        }

        return parent::update($ctx, $existing, $data);
    }
}
