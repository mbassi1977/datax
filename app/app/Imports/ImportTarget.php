<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;

/** Un destino de importación (qué entidad HRX se carga y cómo se valida). */
abstract class ImportTarget
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Prefijo de permisos: "personas" => personas.crear / personas.editar */
    abstract public function permission(): string;

    /** @return array<string, array{label:string, required?:bool, synonyms?:string[], example?:string}> */
    abstract public function fields(): array;

    /** Campos que deben estar relacionados con una columna (algunos pueden ser opcionales según otro campo). */
    public function requiredFields(array $mapping): array
    {
        $out = [];
        foreach ($this->fields() as $key => $def) {
            $unless = $def['required_unless'] ?? null;
            $satisfied = ($mapping[$key] ?? null) !== null || ($unless && ($mapping[$unless] ?? null) !== null);
            if (($def['required'] ?? false) && ! $satisfied) {
                $out[$key] = $def['label'];
            }
        }

        return $out;
    }

    /**
     * Normaliza y valida una fila ya mapeada (campo => texto crudo).
     *
     * @return array{0: array, 1: array<string,string>} [datos limpios (solo campos con valor), errores campo => mensaje]
     */
    abstract public function normalize(array $raw, ImportContext $ctx): array;

    abstract public function findExisting(ImportContext $ctx, array $data): ?Model;

    /** Clave para detectar duplicados dentro del archivo. */
    abstract public function dedupeKey(array $data): ?string;

    /** Mensaje de error si el usuario no puede crear / actualizar; null si puede. */
    abstract public function denyCreate(ImportContext $ctx, array $data): ?string;

    abstract public function denyUpdate(ImportContext $ctx, Model $existing, array $data): ?string;

    abstract public function create(ImportContext $ctx, array $data): Model;

    public function update(ImportContext $ctx, Model $existing, array $data): Model
    {
        $existing->update($data + ['updated_by' => $ctx->user->id]);

        return $existing;
    }

    protected function required(array $raw, string $field, array &$errors): ?string
    {
        $v = trim((string) ($raw[$field] ?? ''));
        if ($v === '') {
            $errors[$field] = 'Es obligatorio.';

            return null;
        }

        return $v;
    }

    protected function text(array $raw, string $field, array &$data, int $max = 255, array &$errors = []): void
    {
        $v = trim((string) ($raw[$field] ?? ''));
        if ($v === '') {
            return;
        }
        if (mb_strlen($v) > $max) {
            $errors[$field] = "Máximo $max caracteres.";

            return;
        }
        $data[$field] = $v;
    }

    protected function email(array $raw, array &$data, array &$errors): void
    {
        $v = strtolower(trim((string) ($raw['email'] ?? '')));
        if ($v === '') {
            return;
        }
        filter_var($v, FILTER_VALIDATE_EMAIL) ? $data['email'] = $v : $errors['email'] = 'Correo inválido.';
    }

    protected function rut(array $raw, array &$data, array &$errors): void
    {
        $v = trim((string) ($raw['rut'] ?? ''));
        if ($v === '') {
            return;
        }
        Rut::isValid($v) ? $data['rut'] = Rut::format($v) : $errors['rut'] = 'RUT inválido (revise el dígito verificador).';
    }
}
