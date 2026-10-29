<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security.users', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('person_id')->nullable()->constrained('master.people');
            $t->foreignUuid('organization_id')->nullable()->constrained('core.organizations'); // organización principal
            $t->string('email');
            $t->string('password');
            $t->string('status', 20)->default('active'); // active, suspended, blocked, deleted
            $t->timestampTz('email_verified_at')->nullable();
            $t->timestampTz('last_login_at')->nullable();
            $t->string('auth_method', 30)->default('password');
            $t->boolean('is_superadmin')->default(false);
            $t->rememberToken();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['tenant_id', 'email']);
        });

        Schema::create('security.profiles', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->uuid('tenant_id')->nullable(); // null = perfil de sistema
            $t->foreign('tenant_id')->references('id')->on('core.tenants');
            $t->string('code', 50);
            $t->string('name');
            $t->boolean('is_system')->default(false);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        // Permisos con formato recurso.accion (personas.ver, personas.crear...).
        Schema::create('security.permissions', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->string('code', 100)->unique();
            $t->string('description')->nullable();
        });

        Schema::create('security.profile_permissions', function (Blueprint $t) {
            $t->foreignUuid('profile_id')->constrained('security.profiles')->cascadeOnDelete();
            $t->foreignUuid('permission_id')->constrained('security.permissions')->cascadeOnDelete();
            $t->primary(['profile_id', 'permission_id']);
        });

        // Un usuario puede tener varios perfiles; cada asignación lleva su alcance (dónde puede actuar).
        Schema::create('security.user_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('user_id')->constrained('security.users')->cascadeOnDelete();
            $t->foreignUuid('profile_id')->constrained('security.profiles');
            $t->string('scope_type', 20)->default('organization'); // global, holding, organization, branch, area, project
            $t->uuid('scope_id')->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable(); // delegaciones y suplencias temporales
            $t->uuid('delegated_by')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'scope_type', 'scope_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('security.user_profiles');
        Schema::dropIfExists('security.profile_permissions');
        Schema::dropIfExists('security.permissions');
        Schema::dropIfExists('security.profiles');
        Schema::dropIfExists('security.users');
    }
};
