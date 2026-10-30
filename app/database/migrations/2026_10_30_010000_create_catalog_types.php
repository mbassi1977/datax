<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tipos de catálogo: del sistema (tenant_id nulo) o creados por un tenant con el constructor de catálogos.
        Schema::create('core.catalog_types', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->uuid('tenant_id')->nullable();
            $t->foreign('tenant_id')->references('id')->on('core.tenants');
            $t->string('key', 50);
            $t->string('label');
            $t->text('description')->nullable();
            $t->boolean('allow_tenant_items')->default(true); // en tipos del sistema: ¿puede el tenant agregar sus propias entradas?
            $t->timestampsTz();
            $t->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core.catalog_types');
    }
};
