<?php

namespace App\Imports;

use App\Models\SelloutTarget as Goal;
use Illuminate\Database\Eloquent\Model;

class SelloutGoalTarget extends SelloutTarget
{
    public function key(): string { return 'sellout_goals'; }
    public function label(): string { return 'Sell-out · Metas'; }

    public function fields(): array
    {
        return [
            'chain' => $this->chainField(),
            'code' => $this->codeField(),
            'month' => ['label' => 'Mes de la meta', 'required' => true, 'synonyms' => ['mes asignado', 'mes', 'periodo', 'fecha'], 'example' => 'Octubre'],
            'boxes' => ['label' => 'Meta (cajas)', 'required' => true, 'synonyms' => ['meta mes cajas', 'meta mes', 'meta cajas', 'meta', 'cajas meta'], 'example' => '12,5'],
        ];
    }

    public function extraOptions(): array
    {
        return parent::extraOptions() + [1 => ['key' => 'year', 'type' => 'number', 'label' => 'Año (si el mes viene sin año)']];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        $data['chain'] = $this->chain($ctx, $raw, $errors);
        if ($v = $this->required($raw, 'code', $errors)) {
            $data['code'] = $v;
        }
        $m = self::monthStart($raw['month'] ?? '', null, $ctx->options['extra']['year'] ?? null, (int) now()->year);
        $m ? $data['period_start'] = $m : $errors['month'] = 'Mes no reconocido (use «Octubre», «oct 2026» o una fecha).';
        $b = \App\Support\Sellout\CommuneLocator::number($raw['boxes'] ?? '');
        ($b !== null && $b >= 0) ? $data['boxes'] = $b : $errors['boxes'] = 'La meta debe ser un número de cajas.';
        if ($errors === [] && ! str_starts_with($data['chain'], 'new:')) {
            $p = $this->findPoint($ctx, $data['chain'], $data['code']);
            $p ? $data['point_id'] = $p->id : $errors['code'] = "No existe la sala «{$data['code']}» en esa cadena. Cargue primero las salas.";
        } elseif ($errors === []) {
            $errors['chain'] = 'La cadena no existe: cargue primero las salas.';
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        return Goal::where('point_id', $data['point_id'] ?? null)->where('period_start', $data['period_start'] ?? null)->first();
    }

    public function dedupeKey(array $data): ?string { return isset($data['point_id'], $data['period_start']) ? $data['point_id'].'|'.$data['period_start'] : null; }

    public function create(ImportContext $ctx, array $data): Model
    {
        return Goal::create(['tenant_id' => $ctx->tenantId(), 'organization_id' => $this->org($ctx), 'point_id' => $data['point_id'], 'period_start' => $data['period_start'], 'boxes' => $data['boxes']]);
    }

    public function update(ImportContext $ctx, Model $existing, array $data): Model
    {
        $existing->update(['boxes' => $data['boxes']]);

        return $existing;
    }
}
