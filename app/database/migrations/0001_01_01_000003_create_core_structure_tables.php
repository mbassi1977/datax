<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Campos corporativos estándar (cap. 8.19). */
    private function standard(Blueprint $t): void
    {
        $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
        $t->timestampsTz();
        $t->uuid('created_by')->nullable();
        $t->uuid('updated_by')->nullable();
        $t->softDeletesTz();
    }

    public function up(): void
    {
        Schema::create('core.tenants', function (Blueprint $t) {
            $this->standard($t);
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('status', 20)->default('active');
        });

        Schema::create('core.holdings', function (Blueprint $t) {
            $this->standard($t);
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->string('name');
        });

        Schema::create('core.organizations', function (Blueprint $t) {
            $this->standard($t);
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('holding_id')->nullable()->constrained('core.holdings');
            $t->string('type', 30)->default('company'); // company, client, provider, otec, evaluator, public, social
            $t->string('rut', 20)->nullable();
            $t->string('legal_name');
            $t->string('trade_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('website')->nullable();
            $t->string('country', 2)->default('CL');
            $t->string('region')->nullable();
            $t->string('commune')->nullable();
            $t->string('address')->nullable();
            $t->string('industry')->nullable();
            $t->string('status', 20)->default('active');
            $t->unique(['tenant_id', 'rut']);
        });

        Schema::create('core.branches', function (Blueprint $t) {
            $this->standard($t);
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('commune')->nullable();
            $t->string('status', 20)->default('active');
        });

        // Áreas jerárquicas (gerencias, unidades, equipos).
        Schema::create('core.areas', function (Blueprint $t) {
            $this->standard($t);
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->foreignUuid('branch_id')->nullable()->constrained('core.branches');
            $t->uuid('parent_id')->nullable();
            $t->string('name');
            $t->string('status', 20)->default('active');
        });

        Schema::table('core.areas', fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('core.areas'));

        Schema::create('core.cost_centers', function (Blueprint $t) {
            $this->standard($t);
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->foreignUuid('area_id')->nullable()->constrained('core.areas');
            $t->string('code', 30);
            $t->string('name');
            $t->string('status', 20)->default('active');
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('core.catalogs', function (Blueprint $t) {
            $this->standard($t);
            $t->uuid('tenant_id')->nullable(); // null = catálogo global
            $t->foreign('tenant_id')->references('id')->on('core.tenants');
            $t->string('catalog', 50);
            $t->string('code', 50);
            $t->string('label');
            $t->integer('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'catalog', 'code']);
        });
    }

    public function down(): void
    {
        foreach (['catalogs', 'cost_centers', 'areas', 'branches', 'organizations', 'holdings', 'tenants'] as $table) {
            Schema::dropIfExists("core.$table");
        }
    }
};
