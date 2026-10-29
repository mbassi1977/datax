<?php

namespace App\Imports;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;

class OrganizationTarget extends ImportTarget
{
    public function key(): string { return 'organizations'; }
    public function label(): string { return 'Organizaciones'; }
    public function permission(): string { return 'organizaciones'; }

    public function fields(): array
    {
        return [
            'rut' => ['label' => 'RUT', 'synonyms' => ['rut empresa', 'rut organizacion'], 'example' => '76.000.000-0'],
            'legal_name' => ['label' => 'Razón social', 'required' => true, 'synonyms' => ['nombre', 'empresa', 'organizacion', 'razon social empresa'], 'example' => 'Empresa Ejemplo SpA'],
            'trade_name' => ['label' => 'Nombre de fantasía', 'synonyms' => ['nombre fantasia', 'fantasia'], 'example' => 'Ejemplo'],
            'type' => ['label' => 'Tipo', 'synonyms' => ['tipo organizacion'], 'example' => 'Empresa'],
            'email' => ['label' => 'Correo', 'synonyms' => ['email', 'mail'], 'example' => 'contacto@ejemplo.cl'],
            'phone' => ['label' => 'Teléfono', 'synonyms' => ['telefono', 'fono'], 'example' => '+56 2 2345 6789'],
            'region' => ['label' => 'Región', 'synonyms' => [], 'example' => 'Metropolitana'],
            'commune' => ['label' => 'Comuna', 'synonyms' => [], 'example' => 'Santiago'],
            'address' => ['label' => 'Dirección', 'synonyms' => ['domicilio'], 'example' => 'Av. Principal 123'],
            'industry' => ['label' => 'Industria', 'synonyms' => ['rubro', 'giro'], 'example' => 'Servicios'],
        ];
    }

    private const TYPES = [
        'empresa' => 'company', 'company' => 'company', 'cliente' => 'client', 'client' => 'client', 'proveedor' => 'provider', 'provider' => 'provider',
        'otec' => 'otec', 'centro evaluador' => 'evaluator', 'evaluador' => 'evaluator', 'evaluator' => 'evaluator',
        'institucion publica' => 'public', 'publica' => 'public', 'public' => 'public', 'organizacion social' => 'social', 'social' => 'social',
    ];

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];

        if ($v = $this->required($raw, 'legal_name', $errors)) {
            mb_strlen($v) > 255 ? $errors['legal_name'] = 'Máximo 255 caracteres.' : $data['legal_name'] = $v;
        }
        foreach (['trade_name', 'region', 'commune', 'address', 'industry'] as $f) {
            $this->text($raw, $f, $data, 255, $errors);
        }
        $this->text($raw, 'phone', $data, 30, $errors);
        $this->rut($raw, $data, $errors);
        $this->email($raw, $data, $errors);

        if (($t = trim((string) ($raw['type'] ?? ''))) !== '') {
            ($type = self::TYPES[Cast::norm($t)] ?? null) ? $data['type'] = $type : $errors['type'] = 'Tipo no reconocido (Empresa, Cliente, Proveedor, OTEC, Centro evaluador, Institución pública, Organización social).';
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        $q = Organization::where('tenant_id', $ctx->tenantId());
        if (! empty($data['rut'])) {
            return $q->whereRaw("replace(replace(upper(rut), '.', ''), '-', '') = ?", [Rut::key($data['rut'])])->first();
        }

        return $q->whereRaw('lower(legal_name) = ?', [mb_strtolower($data['legal_name'] ?? '')])->first();
    }

    public function dedupeKey(array $data): ?string
    {
        return ! empty($data['rut']) ? Rut::key($data['rut']) : (isset($data['legal_name']) ? 'n:'.Cast::norm($data['legal_name']) : null);
    }

    /** Igual que la API: crear organizaciones exige alcance global. */
    public function denyCreate(ImportContext $ctx, array $data): ?string
    {
        return $ctx->allowed('organizaciones.crear') === null ? null : 'Crear organizaciones requiere alcance global.';
    }

    public function denyUpdate(ImportContext $ctx, Model $existing, array $data): ?string
    {
        return $ctx->canOnOrg('organizaciones.editar', $existing->id) ? null : 'La organización existente está fuera de su alcance.';
    }

    public function create(ImportContext $ctx, array $data): Model
    {
        return Organization::create($data + ['tenant_id' => $ctx->tenantId(), 'created_by' => $ctx->user->id]);
    }
}
