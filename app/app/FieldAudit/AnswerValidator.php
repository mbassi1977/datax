<?php

namespace App\FieldAudit;

use InvalidArgumentException;

/** Valida y normaliza el valor de una respuesta según el tipo de la pregunta. Lanza InvalidArgumentException con un mensaje legible. */
class AnswerValidator
{
    public static function normalize(array $q, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        switch ($q['type']) {
            case 'yes_no':
                if (! in_array($value, ['yes', 'no', 'na'], true)) {
                    throw new InvalidArgumentException('Responda Sí, No o N/A.');
                }

                return $value;
            case 'scale':
                $min = $q['scale']['min'];
                $max = $q['scale']['max'];
                if (! is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
                    throw new InvalidArgumentException("Use un valor entero entre {$min} y {$max}.");
                }

                return (int) $value;
            case 'choice':
                if (! is_string($value) || ! in_array($value, array_column($q['options'], 'id'), true)) {
                    throw new InvalidArgumentException('Opción no válida.');
                }

                return $value;
            case 'multi_choice':
                if (! is_array($value) || array_diff($value, array_column($q['options'], 'id')) !== []) {
                    throw new InvalidArgumentException('Opciones no válidas.');
                }

                return array_values(array_unique($value));
            case 'number':
                $n = $q['number'];
                if (! is_numeric($value) || ($n['min'] !== null && $value < $n['min']) || ($n['max'] !== null && $value > $n['max'])) {
                    throw new InvalidArgumentException('Número fuera del rango permitido.');
                }

                return $value + 0;
            case 'text':
                if (! is_string($value) || mb_strlen($value) > 2000) {
                    throw new InvalidArgumentException('Texto demasiado largo (máx. 2000 caracteres).');
                }

                return trim($value);
            case 'date':
                if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                    throw new InvalidArgumentException('Fecha no válida (use aaaa-mm-dd).');
                }

                return $value;
            case 'gps':
                if (! is_array($value) || ! isset($value['lat'], $value['lng']) || ! is_numeric($value['lat']) || ! is_numeric($value['lng'])
                    || abs($value['lat']) > 90 || abs($value['lng']) > 180) {
                    throw new InvalidArgumentException('Ubicación no válida.');
                }

                return ['lat' => (float) $value['lat'], 'lng' => (float) $value['lng'], 'accuracy' => isset($value['accuracy']) && is_numeric($value['accuracy']) ? (float) $value['accuracy'] : null];
            default: // photo, signature
                throw new InvalidArgumentException('Esta pregunta se responde subiendo una imagen.');
        }
    }
}
