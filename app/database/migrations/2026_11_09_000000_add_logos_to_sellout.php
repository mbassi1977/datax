<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellout.chains', function (Blueprint $t) {
            $t->string('logo_path')->nullable();
            $t->string('logo_mime', 40)->nullable();
        });

        // Formatos de una cadena (p. ej. Jumbo, Santa Isabel y Spid dentro de Cencosud): se reconocen por el nombre de la sala.
        Schema::create('sellout.banners', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->foreignUuid('chain_id')->constrained('sellout.chains');
            $t->string('name');
            $t->jsonb('aliases')->default('[]');
            $t->string('logo_path')->nullable();
            $t->string('logo_mime', 40)->nullable();
            $t->integer('sort')->default(0);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['chain_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellout.banners');
        Schema::table('sellout.chains', fn (Blueprint $t) => $t->dropColumn(['logo_path', 'logo_mime']));
    }
};
