<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Datos de prueba (solo entornos de desarrollo): php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SecuritySeeder::class);
        $this->call(AvatarSeeder::class);
        $this->call(CatalogSeeder::class);

        $tenant = Tenant::firstOrCreate(['slug' => 'demo'], ['name' => 'Demo HRX']);
        $org = Organization::firstOrCreate(
            ['tenant_id' => $tenant->id, 'rut' => '76.000.000-0'],
            ['legal_name' => 'Empresa Demo SpA', 'type' => 'company'],
        );

        $password = env('DEMO_PASSWORD', 'demo-hrx-2026');
        $user = User::firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'admin@demo.cl'],
            ['password' => $password, 'organization_id' => $org->id],
        );

        $profileId = DB::table('security.profiles')->whereNull('tenant_id')->where('code', 'admin')->value('id');
        DB::table('security.user_profiles')->updateOrInsert(
            ['user_id' => $user->id, 'profile_id' => $profileId, 'scope_type' => 'global'],
            ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
        );

        $this->command?->info("Tenant: demo · usuario: admin@demo.cl · clave: {$password}");
    }
}
