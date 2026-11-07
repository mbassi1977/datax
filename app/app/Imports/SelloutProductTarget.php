<?php

namespace App\Imports;

use App\Models\SelloutProduct;
use Illuminate\Database\Eloquent\Model;

/** Catálogo de productos del cliente: nombre, equivalencias con cada cadena, color y unidades por caja. La imagen se sube aparte, en Catálogo. */
class SelloutProductTarget extends SelloutTarget
{
    public function key(): string { return 'sellout_products'; }
    public function label(): string { return 'Sell-out · Catálogo de productos'; }
    public function extraOptions(): array { return []; }

    public function fields(): array
    {
        return [
            'name' => ['label' => 'Producto', 'required' => true, 'synonyms' => ['nombre', 'nombre producto', 'producto'], 'example' => 'Minga Loca'],
            'aliases' => ['label' => 'Equivalencias (separadas por ; o coma)', 'synonyms' => ['equivalencias', 'alias', 'nombres en cadenas', 'textos'], 'example' => 'minga; minga loca ipa'],
            'color' => ['label' => 'Color (#RRGGBB)', 'synonyms' => ['color', 'color hex'], 'example' => '#eb6834'],
            'units_per_box' => ['label' => 'Unidades por caja', 'synonyms' => ['unidades por caja', 'un por caja', 'caja'], 'example' => '12'],
            'active' => ['label' => 'Activo (Sí/No)', 'synonyms' => ['activo', 'estado', 'vigente'], 'example' => 'Sí'],
        ];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        if (! $this->org($ctx)) {
            $errors['*'] = 'Elija el cliente (marca) en las opciones de la carga.';
        }
        if ($v = $this->required($raw, 'name', $errors)) {
            mb_strlen($v) > 150 ? $errors['name'] = 'Máximo 150 caracteres.' : $data['name'] = $v;
        }
        if (($a = trim((string) ($raw['aliases'] ?? ''))) !== '') {
            $data['aliases'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/[;,|]/', $a)))));
        }
        if (($c = trim((string) ($raw['color'] ?? ''))) !== '') {
            $c = str_starts_with($c, '#') ? $c : '#'.$c;
            preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $data['color'] = strtolower($c) : $errors['color'] = 'Color inválido (use #RRGGBB, p. ej. #2a78d6).';
        }
        if (($u = trim((string) ($raw['units_per_box'] ?? ''))) !== '') {
            ctype_digit($u) && (int) $u >= 1 && (int) $u <= 1000 ? $data['units_per_box'] = (int) $u : $errors['units_per_box'] = 'Debe ser un entero entre 1 y 1000.';
        }
        if (($s = trim((string) ($raw['active'] ?? ''))) !== '') {
            $st = Cast::status($s);
            $st ? $data['active'] = $st === 'active' : $errors['active'] = 'Use Sí / No.';
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        return SelloutProduct::where('organization_id', $this->org($ctx))->whereRaw('lower(name) = ?', [mb_strtolower($data['name'] ?? '')])->first();
    }

    public function dedupeKey(array $data): ?string { return isset($data['name']) ? Cast::norm($data['name']) : null; }

    public function create(ImportContext $ctx, array $data): Model
    {
        return SelloutProduct::create($data + ['tenant_id' => $ctx->tenantId(), 'organization_id' => $this->org($ctx)]);
    }
}
