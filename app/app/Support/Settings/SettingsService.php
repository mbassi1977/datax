<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;

class SettingsService
{
    /** Valores configurados exactamente en un ámbito (organization_id nulo = tenant). */
    public function rawAt(string $tenantId, ?string $orgId): array
    {
        return Setting::where('tenant_id', $tenantId)->when($orgId, fn ($q) => $q->where('organization_id', $orgId), fn ($q) => $q->whereNull('organization_id'))
            ->get()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
    }

    /** Valor efectivo: organización → tenant → defecto. */
    public function effective(string $tenantId, ?string $orgId): array
    {
        $values = SettingsRegistry::defaults();
        $layers = [$this->rawAt($tenantId, null)] + ($orgId ? [1 => $this->rawAt($tenantId, $orgId)] : []);
        foreach ($layers as $layer) {
            foreach ($layer as $k => $v) {
                if (array_key_exists($k, $values) && $v !== null) {
                    $values[$k] = $v;
                }
            }
        }

        return $values;
    }

    public function set(string $tenantId, ?string $orgId, string $key, mixed $value, ?string $userId): void
    {
        $row = Setting::where('tenant_id', $tenantId)->where('key', $key)->when($orgId, fn ($q) => $q->where('organization_id', $orgId), fn ($q) => $q->whereNull('organization_id'))->first();

        if ($value === null) {
            $row?->delete();   // sin valor propio: hereda del nivel superior
        } elseif ($row) {
            $row->update(['value' => $value, 'updated_by' => $userId]);
        } else {
            Setting::create(['tenant_id' => $tenantId, 'organization_id' => $orgId, 'key' => $key, 'value' => $value, 'updated_by' => $userId]);
        }
    }

    /** Fila que aporta el logo vigente: la de la organización o, si no tiene, la del tenant. */
    public function logoRow(string $tenantId, ?string $orgId): ?Setting
    {
        $rows = Setting::where('tenant_id', $tenantId)->where('key', 'brand.logo')->where(fn ($q) => $q->whereNull('organization_id')->when($orgId, fn ($w) => $w->orWhere('organization_id', $orgId)))->get();

        return $rows->firstWhere('organization_id', $orgId) ?? $rows->firstWhere('organization_id', null);
    }

    public function logoUrl(?Setting $row): ?string
    {
        return $row ? URL::temporarySignedRoute('media.logo', now()->startOfHour()->addHours(12), ['setting' => $row->id, 'v' => $row->updated_at?->timestamp], absolute: false) : null;
    }

    /** Lo que el cliente necesita para pintar la interfaz (marca, regional y flags de seguridad). */
    public function forClient(string $tenantId, ?string $orgId): array
    {
        $e = $this->effective($tenantId, $orgId);

        return [
            'brand' => ['name' => $e['brand.name'], 'primary_color' => $e['brand.primary_color'], 'logo_url' => $this->logoUrl($this->logoRow($tenantId, $orgId))],
            'locale' => ['timezone' => $e['locale.timezone'], 'date_format' => $e['locale.date_format']],
            'security' => ['mfa_required' => (bool) $e['security.mfa_required'], 'password_min_length' => $e['security.password_min_length']],
        ];
    }

    /** Regla de contraseña según la política de la organización (o del tenant). */
    public function passwordRule(string $tenantId, ?string $orgId): Password
    {
        $e = $this->effective($tenantId, $orgId);
        $rule = Password::min((int) $e['security.password_min_length'])->letters()->numbers();
        $e['security.password_require_mixed_case'] && $rule->mixedCase();
        $e['security.password_require_symbol'] && $rule->symbols();

        return $rule;
    }
}
