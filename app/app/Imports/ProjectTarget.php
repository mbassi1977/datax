<?php

namespace App\Imports;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProjectTarget extends OrgScopedTarget
{
    private const STATUS = [
        'planificado' => 'planned', 'planificada' => 'planned', 'planned' => 'planned', 'en planificacion' => 'planned',
        'activo' => 'active', 'activa' => 'active', 'active' => 'active', 'en curso' => 'active', 'vigente' => 'active',
        'pausado' => 'paused', 'pausada' => 'paused', 'paused' => 'paused', 'detenido' => 'paused',
        'cerrado' => 'closed', 'cerrada' => 'closed', 'closed' => 'closed', 'terminado' => 'closed', 'finalizado' => 'closed',
    ];

    public function key(): string { return 'projects'; }
    public function label(): string { return 'Proyectos'; }
    public function permission(): string { return 'proyectos'; }

    public function fields(): array
    {
        return [
            'organization' => $this->orgField(),
            'code' => ['label' => 'Código', 'synonyms' => ['codigo proyecto', 'id proyecto'], 'example' => 'PRY-001'],
            'name' => ['label' => 'Nombre del proyecto', 'required' => true, 'synonyms' => ['proyecto', 'nombre'], 'example' => 'Implementación 2026'],
            'description' => ['label' => 'Descripción', 'synonyms' => ['detalle', 'alcance'], 'example' => 'Despliegue en todas las sucursales'],
            'starts_on' => ['label' => 'Fecha de inicio', 'synonyms' => ['inicio', 'fecha inicio', 'desde'], 'example' => '01/03/2026'],
            'ends_on' => ['label' => 'Fecha de término', 'synonyms' => ['termino', 'fin', 'fecha termino', 'hasta', 'fecha fin'], 'example' => '31/12/2026'],
            'status' => ['label' => 'Estado (Planificado, Activo, Pausado, Cerrado)', 'synonyms' => ['estado', 'estado proyecto'], 'example' => 'Activo'],
            'cost_center' => ['label' => 'Centro de costo (código)', 'synonyms' => ['centro de costo', 'cc', 'codigo cc'], 'example' => 'CC-100'],
            'manager' => ['label' => 'Responsable (RUT o correo de la persona)', 'synonyms' => ['responsable', 'jefe de proyecto', 'encargado', 'gerente'], 'example' => 'ana@empresa.cl'],
        ];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        $this->resolveOrg($raw, $ctx, $data, $errors);
        $this->name($raw, $data, $errors);
        $this->text($raw, 'description', $data, 5000, $errors);
        if (($c = trim((string) ($raw['code'] ?? ''))) !== '') {
            mb_strlen($c) > 30 ? $errors['code'] = 'Máximo 30 caracteres.' : $data['code'] = $c;
        }
        foreach (['starts_on', 'ends_on'] as $f) {
            if (($d = trim((string) ($raw[$f] ?? ''))) !== '') {
                ($date = Cast::date($d)) ? $data[$f] = $date : $errors[$f] = 'Fecha inválida (use dd/mm/aaaa).';
            }
        }
        if (isset($data['starts_on'], $data['ends_on']) && $data['ends_on'] < $data['starts_on']) {
            $errors['ends_on'] = 'El término no puede ser anterior al inicio.';
        }
        if (($s = trim((string) ($raw['status'] ?? ''))) !== '') {
            ($st = self::STATUS[Cast::norm($s)] ?? null) ? $data['status'] = $st : $errors['status'] = 'Estado no reconocido (Planificado, Activo, Pausado, Cerrado).';
        }
        if (isset($data['organization_id']) && ($cc = trim((string) ($raw['cost_center'] ?? ''))) !== '') {
            $row = $this->lookup('core.cost_centers', $ctx, $data['organization_id'], 'code', $cc);
            $row ? $data['cost_center_id'] = $row->id : $errors['cost_center'] = "No existe el centro de costo «{$cc}» en esa organización.";
        }
        if (($m = trim((string) ($raw['manager'] ?? ''))) !== '') {
            $q = DB::table('master.people')->where('tenant_id', $ctx->tenantId())->whereNull('deleted_at');
            $row = str_contains($m, '@')
                ? $q->whereRaw('lower(email) = ?', [mb_strtolower($m)])->first()
                : $q->whereRaw("replace(replace(upper(rut), '.', ''), '-', '') = ?", [Rut::key($m)])->first();
            $row ? $data['manager_id'] = $row->id : $errors['manager'] = "No se encontró a la persona «{$m}» (use RUT o correo registrado).";
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        $q = Project::where('tenant_id', $ctx->tenantId());
        if (! empty($data['code'])) {
            return $q->whereRaw('lower(code) = ?', [mb_strtolower($data['code'])])->first();
        }

        return $q->where('organization_id', $data['organization_id'] ?? null)->whereRaw('lower(name) = ?', [mb_strtolower($data['name'] ?? '')])->first();
    }

    public function dedupeKey(array $data): ?string
    {
        return ! empty($data['code']) ? 'c:'.Cast::norm($data['code']) : (isset($data['name'], $data['organization_id']) ? $data['organization_id'].'|'.Cast::norm($data['name']) : null);
    }

    public function create(ImportContext $ctx, array $data): Model
    {
        return Project::create($data + ['tenant_id' => $ctx->tenantId(), 'created_by' => $ctx->user->id]);
    }
}
