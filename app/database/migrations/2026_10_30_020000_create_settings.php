<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parámetros por tenant (organization_id nulo) y por organización (reemplaza al del tenant).
        Schema::create('core.settings', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->uuid('organization_id')->nullable();
            $t->foreign('organization_id')->references('id')->on('core.organizations')->cascadeOnDelete();
            $t->string('key', 80);
            $t->jsonb('value')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
        });
        DB::statement('create unique index settings_tenant_key on core.settings (tenant_id, key) where organization_id is null');
        DB::statement('create unique index settings_org_key on core.settings (organization_id, key) where organization_id is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('core.settings');
    }
};
