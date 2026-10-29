<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Perfiles de sistema y permisos base del núcleo (cap. 13). */
class SecuritySeeder extends Seeder
{
    private array $resources = ['organizaciones', 'importaciones', 'sucursales', 'areas', 'centros_costo', 'personas', 'proyectos', 'usuarios', 'perfiles', 'catalogos', 'auditoria'];
    private array $actions = ['ver', 'crear', 'editar', 'eliminar'];

    private array $profiles = [
        'superadmin' => 'Superadministrador',
        'admin' => 'Administrador',
        'gestor_inclusion' => 'Gestor de Inclusión',
        'consultor' => 'Consultor',
        'relator' => 'Relator',
        'evaluador' => 'Evaluador',
        'supervisor' => 'Supervisor',
        'colaborador' => 'Colaborador',
    ];

    public function run(): void
    {
        $permissionIds = [];
        foreach ($this->resources as $resource) {
            foreach ($this->actions as $action) {
                $code = "$resource.$action";
                DB::table('security.permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code]);
                $permissionIds[$code] = DB::table('security.permissions')->where('code', $code)->value('id');
            }
        }

        foreach ($this->profiles as $code => $name) {
            DB::table('security.profiles')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => null, 'code' => $code,
                'name' => $name, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Admin: todos los permisos. Colaborador: solo lectura de personas y proyectos.
        $grant = fn (string $profile, array $codes) => collect($codes)->each(fn ($c) => DB::table('security.profile_permissions')->insertOrIgnore([
            'profile_id' => DB::table('security.profiles')->whereNull('tenant_id')->where('code', $profile)->value('id'),
            'permission_id' => $permissionIds[$c],
        ]));
        $grant('admin', array_keys($permissionIds));
        $grant('colaborador', ['personas.ver', 'proyectos.ver']);
    }
}
