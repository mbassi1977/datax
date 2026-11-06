<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;

/** Base de los destinos que pertenecen a una organización (sucursales, áreas, centros de costo, proyectos). */
abstract class OrgScopedTarget extends ImportTarget
{
    /** La interfaz ofrece "Organización por defecto" para las filas sin organización. */
    public function usesDefaultOrg(): bool { return true; }

    protected function orgField(): array
    {
        return ['label' => 'Organización (RUT o nombre)', 'synonyms' => ['empresa', 'organizacion', 'razon social', 'rut empresa'], 'example' => '76.000.000-0'];
    }

    /** Organización de la fila: su columna o la organización por defecto del asistente. */
    protected function resolveOrg(array $raw, ImportContext $ctx, array &$data, array &$errors): void
    {
        $v = trim((string) ($raw['organization'] ?? ''));
        if ($v !== '') {
            $org = $ctx->findOrg($v);
            $org ? $data['organization_id'] = $org->id : $errors['organization'] = "No existe la organización «{$v}».";
        } elseif (! empty($ctx->options['organization_id'])) {
            $data['organization_id'] = $ctx->options['organization_id'];
        } else {
            $errors['organization'] = 'Indique la organización (columna o «Organización por defecto»).';
        }
    }

    protected function name(array $raw, array &$data, array &$errors, string $field = 'name'): void
    {
        if ($v = $this->required($raw, $field, $errors)) {
            mb_strlen($v) > 255 ? $errors[$field] = 'Máximo 255 caracteres.' : $data[$field] = $v;
        }
    }

    protected function status(array $raw, array &$data, array &$errors): void
    {
        if (($s = trim((string) ($raw['status'] ?? ''))) !== '') {
            ($st = Cast::status($s)) ? $data['status'] = $st : $errors['status'] = 'Estado no reconocido (Activo / Inactivo).';
        }
    }

    public function denyCreate(ImportContext $ctx, array $data): ?string
    {
        return $ctx->canOnOrg($this->permission().'.crear', $data['organization_id'] ?? null) ? null : 'La organización está fuera de su alcance.';
    }

    public function denyUpdate(ImportContext $ctx, Model $existing, array $data): ?string
    {
        return $ctx->canOnOrg($this->permission().'.editar', $existing->organization_id) ? null : 'El registro existente está fuera de su alcance.';
    }

    /** Búsqueda en tablas del tenant por organización + nombre (sin distinguir mayúsculas). */
    protected function lookup(string $table, ImportContext $ctx, string $orgId, string $col, string $value, array $where = []): ?object
    {
        return \Illuminate\Support\Facades\DB::table($table)->where('tenant_id', $ctx->tenantId())->where('organization_id', $orgId)->whereNull('deleted_at')
            ->whereRaw("lower($col) = ?", [mb_strtolower(trim($value))])->where($where)->first();
    }
}
