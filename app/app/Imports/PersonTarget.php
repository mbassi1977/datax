<?php

namespace App\Imports;

use App\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PersonTarget extends ImportTarget
{
    private const ROLES = ['trabajador', 'consultor', 'relator', 'evaluador', 'participante', 'cliente', 'medico', 'tutor', 'administrador'];

    public function key(): string { return 'people'; }
    public function label(): string { return 'Personas'; }
    public function permission(): string { return 'personas'; }

    public function fields(): array
    {
        return [
            'rut' => ['label' => 'RUT', 'synonyms' => ['rut trabajador', 'run', 'identificador', 'documento'], 'example' => '12.345.678-5'],
            'first_name' => ['label' => 'Nombres', 'required' => true, 'synonyms' => ['nombre', 'primer nombre'], 'example' => 'Ana'],
            'last_name' => ['label' => 'Apellidos', 'synonyms' => ['apellido', 'apellido paterno'], 'example' => 'Pérez Soto'],
            'email' => ['label' => 'Correo', 'synonyms' => ['email', 'mail', 'correo electronico'], 'example' => 'ana@empresa.cl'],
            'phone' => ['label' => 'Teléfono', 'synonyms' => ['telefono', 'celular', 'fono', 'movil'], 'example' => '+56 9 1234 5678'],
            'birth_date' => ['label' => 'Fecha de nacimiento', 'synonyms' => ['nacimiento', 'fecha nac', 'f nacimiento'], 'example' => '15/03/1990'],
            'sex' => ['label' => 'Sexo', 'synonyms' => ['genero'], 'example' => 'F'],
            'nationality' => ['label' => 'Nacionalidad', 'synonyms' => [], 'example' => 'Chilena'],
            'job_title' => ['label' => 'Cargo', 'synonyms' => ['puesto', 'funcion'], 'example' => 'Analista'],
            'roles' => ['label' => 'Roles', 'synonyms' => ['rol', 'tipo de persona'], 'example' => 'trabajador, relator'],
            'organization' => ['label' => 'Organización (RUT o nombre)', 'synonyms' => ['empresa', 'organizacion', 'razon social', 'rut empresa'], 'example' => '76.000.000-0'],
            'status' => ['label' => 'Estado', 'synonyms' => ['activo', 'vigente'], 'example' => 'Activo'],
        ];
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];

        if ($v = $this->required($raw, 'first_name', $errors)) {
            mb_strlen($v) > 255 ? $errors['first_name'] = 'Máximo 255 caracteres.' : $data['first_name'] = $v;
        }
        $this->text($raw, 'last_name', $data, 255, $errors);
        $this->text($raw, 'phone', $data, 30, $errors);
        $this->text($raw, 'sex', $data, 20, $errors);
        $this->text($raw, 'nationality', $data, 255, $errors);
        $this->text($raw, 'job_title', $data, 255, $errors);
        $this->rut($raw, $data, $errors);
        $this->email($raw, $data, $errors);

        if (($d = trim((string) ($raw['birth_date'] ?? ''))) !== '') {
            ($date = Cast::date($d)) ? $data['birth_date'] = $date : $errors['birth_date'] = 'Fecha inválida (use dd/mm/aaaa).';
        }
        if (($s = trim((string) ($raw['status'] ?? ''))) !== '') {
            ($st = Cast::status($s)) ? $data['status'] = $st : $errors['status'] = 'Estado no reconocido (Activo / Inactivo).';
        }
        if (($r = trim((string) ($raw['roles'] ?? ''))) !== '') {
            $roles = [];
            foreach (preg_split('/[,;|\/]+/', $r) as $part) {
                $role = Cast::norm($part);
                $role = ['trabajadora' => 'trabajador', 'medica' => 'medico', 'relatora' => 'relator'][$role] ?? $role;
                if ($role === '') {
                    continue;
                }
                in_array($role, self::ROLES, true) ? $roles[] = $role : $errors['roles'] = "Rol desconocido: «".trim($part)."». Válidos: ".implode(', ', self::ROLES).'.';
            }
            if (! isset($errors['roles'])) {
                $data['roles'] = array_values(array_unique($roles));
            }
        }

        $orgValue = trim((string) ($raw['organization'] ?? ''));
        if ($orgValue !== '') {
            $org = $ctx->findOrg($orgValue);
            $org ? $data['organization_id'] = $org->id : $errors['organization'] = "No existe la organización «{$orgValue}».";
        } elseif (! empty($ctx->options['organization_id'])) {
            $data['organization_id'] = $ctx->options['organization_id'];
        }

        return [$data, $errors];
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model
    {
        if (empty($data['rut'])) {
            return null;
        }

        return Person::where('tenant_id', $ctx->tenantId())
            ->whereRaw("replace(replace(upper(rut), '.', ''), '-', '') = ?", [Rut::key($data['rut'])])->first();
    }

    public function dedupeKey(array $data): ?string
    {
        return empty($data['rut']) ? null : Rut::key($data['rut']);
    }

    public function denyCreate(ImportContext $ctx, array $data): ?string
    {
        return $ctx->canOnOrg('personas.crear', $data['organization_id'] ?? null) ? null : 'Fuera de su alcance (organización).';
    }

    public function denyUpdate(ImportContext $ctx, Model $existing, array $data): ?string
    {
        if (! $ctx->canOnOrg('personas.editar', $existing->organization_id)) {
            return 'La persona existente está fuera de su alcance.';
        }
        if (isset($data['organization_id']) && ! $ctx->canOnOrg('personas.editar', $data['organization_id'])) {
            return 'La organización indicada está fuera de su alcance.';
        }

        return null;
    }

    public function create(ImportContext $ctx, array $data): Model
    {
        return Person::create($data + ['tenant_id' => $ctx->tenantId(), 'created_by' => $ctx->user->id]);
    }
}
