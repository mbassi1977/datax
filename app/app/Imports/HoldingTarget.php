<?php

namespace App\Imports;

use App\Models\Holding;
use Illuminate\Database\Eloquent\Model;

class HoldingTarget extends ImportTarget
{
    public function key(): string { return 'holdings'; }
    public function label(): string { return 'Holdings'; }
    public function permission(): string { return 'holdings'; }

    public function fields(): array
    {
        return ['name' => ['label' => 'Nombre', 'required' => true, 'synonyms' => ['holding', 'grupo', 'nombre holding'], 'example' => 'Grupo Ejemplo']];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        if ($v = $this->required($raw, 'name', $errors)) {
            mb_strlen($v) > 255 ? $errors['name'] = 'Máximo 255 caracteres.' : $data['name'] = $v;
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        return Holding::where('tenant_id', $ctx->tenantId())->whereRaw('lower(name) = ?', [mb_strtolower($data['name'] ?? '')])->first();
    }

    public function dedupeKey(array $data): ?string { return isset($data['name']) ? Cast::norm($data['name']) : null; }

    public function denyCreate(ImportContext $ctx, array $data): ?string
    {
        return $ctx->allowed('holdings.crear') === null ? null : 'Crear holdings requiere alcance global.';
    }

    public function denyUpdate(ImportContext $ctx, Model $existing, array $data): ?string
    {
        return $ctx->allowed('holdings.editar') === null ? null : 'Editar holdings requiere alcance global.';
    }

    public function create(ImportContext $ctx, array $data): Model
    {
        return Holding::create($data + ['tenant_id' => $ctx->tenantId(), 'created_by' => $ctx->user->id]);
    }
}
