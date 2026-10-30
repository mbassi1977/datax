<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Colecciones de avatares: del sistema (tenant_id nulo) o de un tenant. Se asignan a empresas (pivote) o a todas (is_global).
        Schema::create('core.avatar_collections', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->uuid('tenant_id')->nullable();
            $t->foreign('tenant_id')->references('id')->on('core.tenants');
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_global')->default(false);   // disponible para todas las empresas del tenant
            $t->boolean('is_active')->default(true);
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
        });

        Schema::create('core.avatars', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('collection_id')->constrained('core.avatar_collections')->cascadeOnDelete();
            $t->string('name');
            $t->string('path');                          // disco local, no público: se sirve con URL firmada
            $t->string('mime', 50);
            $t->integer('size')->default(0);
            $t->integer('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->softDeletesTz();
        });

        Schema::create('core.avatar_collection_organization', function (Blueprint $t) {
            $t->foreignUuid('collection_id')->constrained('core.avatar_collections')->cascadeOnDelete();
            $t->foreignUuid('organization_id')->constrained('core.organizations')->cascadeOnDelete();
            $t->timestampsTz();
            $t->primary(['collection_id', 'organization_id']);
        });

        // Avatar del usuario: uno de la galería (avatar_id) o una foto propia (avatar_path), nunca ambos.
        Schema::table('security.users', function (Blueprint $t) {
            $t->uuid('avatar_id')->nullable();
            $t->foreign('avatar_id')->references('id')->on('core.avatars')->nullOnDelete();
            $t->string('avatar_path')->nullable();
            $t->timestampTz('avatar_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('security.users', function (Blueprint $t) {
            $t->dropForeign(['avatar_id']);
            $t->dropColumn(['avatar_id', 'avatar_path', 'avatar_updated_at']);
        });
        Schema::dropIfExists('core.avatar_collection_organization');
        Schema::dropIfExists('core.avatars');
        Schema::dropIfExists('core.avatar_collections');
    }
};
