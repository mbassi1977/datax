<?php

namespace App\Imports;

use App\Models\SelloutPoint;
use Illuminate\Database\Eloquent\Model;

class SelloutPointTarget extends SelloutTarget
{
    public function key(): string { return 'sellout_points'; }
    public function label(): string { return 'Sell-out · Salas'; }

    public function fields(): array
    {
        return [
            'chain' => $this->chainField(),
            'code' => $this->codeField(),
            'name' => ['label' => 'Nombre de la sala'] + $this->nameField() + ['required' => true],
            'address' => ['label' => 'Dirección', 'synonyms' => ['direccion', 'domicilio', 'calle'], 'example' => 'Av. Chicureo 130, Colina'],
            'commune' => ['label' => 'Comuna', 'synonyms' => ['comuna'], 'example' => 'Colina'],
            'latitude' => ['label' => 'Latitud', 'synonyms' => ['lat', 'latitud'], 'example' => '-33.2'],
            'longitude' => ['label' => 'Longitud', 'synonyms' => ['lon', 'lng', 'longitud'], 'example' => '-70.6'],
        ];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        $data['chain'] = $this->chain($ctx, $raw, $errors);
        if ($v = $this->required($raw, 'code', $errors)) {
            mb_strlen($v) > 60 ? $errors['code'] = 'Máximo 60 caracteres.' : $data['code'] = $v;
        }
        if ($v = $this->required($raw, 'name', $errors)) {
            mb_strlen($v) > 255 ? $errors['name'] = 'Máximo 255 caracteres.' : $data['name'] = $v;
        }
        $this->text($raw, 'address', $data, 255, $errors);
        $this->text($raw, 'commune', $data, 255, $errors);
        $lat = trim((string) ($raw['latitude'] ?? ''));
        $lon = trim((string) ($raw['longitude'] ?? ''));
        if ($lat !== '' || $lon !== '') {
            $la = \App\Support\Sellout\CommuneLocator::number($lat);
            $lo = \App\Support\Sellout\CommuneLocator::number($lon);
            if ($la === null || $lo === null || abs($la) > 90 || abs($lo) > 180) {
                $errors['latitude'] = 'Latitud y longitud deben ser números válidos.';
            } else {
                $data['latitude'] = $la;
                $data['longitude'] = $lo;
                $data['geo_quality'] = 'exact';
            }
        }
        if (! isset($data['latitude']) && $errors === []) {
            $g = $this->geo($data['address'] ?? null, $data['name'] ?? null, $data['code'] ?? '');
            if ($g['lat'] !== null) {
                [$data['latitude'], $data['longitude'], $data['geo_quality']] = [$g['lat'], $g['lon'], $g['quality']];
            }
            $data['region'] = $g['region'];
            $data['commune'] = $data['commune'] ?? $g['commune'];
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        return $this->findPoint($ctx, (string) $data['chain'], (string) $data['code']);
    }

    public function dedupeKey(array $data): ?string { return isset($data['chain'], $data['code']) ? $data['chain'].'|'.mb_strtolower($data['code']) : null; }

    public function create(ImportContext $ctx, array $data): Model
    {
        $chain = $data['chain'];
        unset($data['chain']);
        $chainId = str_starts_with($chain, 'new:') ? $chain : $chain;

        return SelloutPoint::create($data + ['tenant_id' => $ctx->tenantId(), 'organization_id' => $this->org($ctx), 'chain_id' => $chainId, 'created_by' => $ctx->user->id]);
    }

    public function update(ImportContext $ctx, Model $existing, array $data): Model
    {
        unset($data['chain']);
        // una ubicación aproximada nunca reemplaza una exacta (geocodificada o corregida a mano)
        if ($existing->geo_quality === 'exact' && ($data['geo_quality'] ?? 'exact') !== 'exact') {
            unset($data['latitude'], $data['longitude'], $data['geo_quality']);
        }

        return parent::update($ctx, $existing, $data);
    }
}
