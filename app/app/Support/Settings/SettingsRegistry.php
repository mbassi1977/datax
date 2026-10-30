<?php

namespace App\Support\Settings;

use DateTimeZone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Parámetros configurables y sus reglas. Valor efectivo = organización → tenant → valor por defecto. */
class SettingsRegistry
{
    public const DATE_FORMATS = ['dd/mm/yyyy', 'yyyy-mm-dd', 'mm/dd/yyyy'];

    /** @return array<string, array{type:string,label:string,group:string,default:mixed,help?:string,min?:int,max?:int,options?:array}> */
    public static function all(): array
    {
        return [
            'brand.name' => ['type' => 'string', 'group' => 'brand', 'label' => 'Nombre visible', 'default' => null, 'max' => 80, 'help' => 'Se muestra en el menú en lugar de «HRX».'],
            'brand.primary_color' => ['type' => 'color', 'group' => 'brand', 'label' => 'Color principal', 'default' => '#1f5eff'],
            'locale.timezone' => ['type' => 'timezone', 'group' => 'locale', 'label' => 'Zona horaria', 'default' => 'America/Santiago'],
            'locale.date_format' => ['type' => 'enum', 'group' => 'locale', 'label' => 'Formato de fecha', 'default' => 'dd/mm/yyyy', 'options' => self::DATE_FORMATS],
            'security.password_min_length' => ['type' => 'int', 'group' => 'security', 'label' => 'Largo mínimo de contraseña', 'default' => 10, 'min' => 8, 'max' => 64],
            'security.password_require_symbol' => ['type' => 'bool', 'group' => 'security', 'label' => 'Exigir un símbolo en la contraseña', 'default' => false],
            'security.password_require_mixed_case' => ['type' => 'bool', 'group' => 'security', 'label' => 'Exigir mayúsculas y minúsculas', 'default' => false],
            'security.mfa_required' => ['type' => 'bool', 'group' => 'security', 'label' => 'Exigir verificación en dos pasos (MFA)', 'default' => false,
                'help' => 'Los usuarios sin MFA deberán activarla para seguir usando la plataforma.'],
        ];
    }

    public static function defaults(): array
    {
        return array_map(fn ($d) => $d['default'], self::all());
    }

    /** Valida un valor para su clave; devuelve el valor normalizado o lanza ValidationException. */
    public static function validate(string $key, mixed $value): mixed
    {
        $def = self::all()[$key] ?? null;
        abort_unless($def, 422, "Parámetro desconocido: {$key}.");

        $rules = match ($def['type']) {
            'string' => ['nullable', 'string', 'max:'.$def['max']],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'enum' => ['required', Rule::in($def['options'])],
            'int' => ['required', 'integer', 'min:'.$def['min'], 'max:'.$def['max']],
            'bool' => ['required', 'boolean'],
        };
        // El nombre del campo no puede llevar puntos (la validación los lee como anidación): se valida como «value».
        $v = validator(['value' => $value], ['value' => $rules], [], ['value' => mb_strtolower($def['label'])]);
        if ($v->fails()) {
            throw ValidationException::withMessages([$key => $v->errors()->get('value')]);
        }

        return match ($def['type']) {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'color' => strtolower($value),
            'string' => ($value === '' ? null : $value),
            default => $value,
        };
    }
}
