<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los nombres y códigos únicos solo cuentan entre registros vigentes: un producto, cadena o formato eliminado
 * (borrado lógico) no debe impedir volver a crearlo con el mismo nombre.
 */
return new class extends Migration
{
    private array $indexes = [
        ['sellout.chains', 'sellout_chains_organization_id_name_unique', 'organization_id, name'],
        ['sellout.products', 'sellout_products_organization_id_name_unique', 'organization_id, name'],
        ['sellout.banners', 'sellout_banners_chain_id_name_unique', 'chain_id, name'],
        ['sellout.points', 'sellout_points_organization_id_chain_id_code_unique', 'organization_id, chain_id, code'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $name, $cols]) {
            DB::statement("alter table {$table} drop constraint if exists {$name}");
            DB::statement("drop index if exists sellout.{$name}");
            DB::statement("create unique index {$name} on {$table} ({$cols}) where deleted_at is null");
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $name, $cols]) {
            DB::statement("drop index if exists sellout.{$name}");
            DB::statement("alter table {$table} add constraint {$name} unique ({$cols})");
        }
    }
};
