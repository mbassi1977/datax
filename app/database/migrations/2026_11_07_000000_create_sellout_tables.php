<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Sell-out: venta en sala de un cliente (marca) en las cadenas de retail, metas, y seguimiento en terreno.
 * La organización dueña de los datos es el cliente (la marca); cada cliente tiene su propio catálogo de productos y cadenas.
 */
return new class extends Migration
{
    private function std(Blueprint $t): void
    {
        $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
        $t->foreignUuid('tenant_id')->constrained('core.tenants');
        $t->foreignUuid('organization_id')->constrained('core.organizations');
    }

    public function up(): void
    {
        DB::statement('create schema if not exists sellout');

        Schema::create('sellout.chains', function (Blueprint $t) {
            $this->std($t);
            $t->string('name');
            $t->string('color', 9)->nullable();
            $t->integer('sort')->default(0);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['organization_id', 'name']);
        });

        // Catálogo de productos del cliente. «aliases» son los textos con que cada cadena nombra el producto (se busca por contención).
        Schema::create('sellout.products', function (Blueprint $t) {
            $this->std($t);
            $t->string('name');
            $t->jsonb('aliases')->default('[]');
            $t->string('color', 9)->nullable();
            $t->string('image_path')->nullable();
            $t->string('image_mime', 40)->nullable();
            $t->integer('units_per_box')->default(12);
            $t->integer('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['organization_id', 'name']);
        });

        // Sala (punto de venta) de una cadena.
        Schema::create('sellout.points', function (Blueprint $t) {
            $this->std($t);
            $t->foreignUuid('chain_id')->constrained('sellout.chains');
            $t->uuid('branch_id')->nullable();                       // vínculo opcional con core.branches
            $t->string('code', 60);
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('commune')->nullable();
            $t->string('region')->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->string('geo_quality', 12)->default('none');          // none | name | commune | exact
            $t->string('status', 20)->default('active');
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->unique(['organization_id', 'chain_id', 'code']);
            $t->index(['organization_id', 'region']);
        });

        // Venta por sala, producto y período (día se agrega a mes al cargar; semana y mes se guardan tal cual).
        Schema::create('sellout.sales', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->foreignUuid('point_id')->constrained('sellout.points');
            $t->foreignUuid('product_id')->constrained('sellout.products');
            $t->date('period_start');
            $t->char('period_type', 1)->default('M');                 // M mes | W semana
            $t->string('channel', 30)->default('SALA');
            $t->decimal('units', 14, 2)->default(0);
            $t->decimal('sales', 16, 2)->default(0);
            $t->decimal('cost', 16, 2)->nullable();
            $t->uuid('import_id')->nullable();
            $t->timestampsTz();
            $t->unique(['point_id', 'product_id', 'period_start', 'period_type', 'channel'], 'sellout_sales_key');
            $t->index(['organization_id', 'period_start']);
        });

        Schema::create('sellout.targets', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->foreignUuid('point_id')->constrained('sellout.points');
            $t->date('period_start');                                 // primer día del mes
            $t->decimal('boxes', 12, 2);
            $t->timestampsTz();
            $t->unique(['point_id', 'period_start']);
        });

        Schema::create('sellout.point_notes', function (Blueprint $t) {
            $this->std($t);
            $t->foreignUuid('point_id')->constrained('sellout.points');
            $t->uuid('user_id');
            $t->text('body');
            $t->timestampsTz();
            $t->index('point_id');
        });

        Schema::create('sellout.point_photos', function (Blueprint $t) {
            $this->std($t);
            $t->foreignUuid('point_id')->constrained('sellout.points');
            $t->uuid('user_id');
            $t->string('path');
            $t->string('mime', 40);
            $t->unsignedInteger('size')->default(0);
            $t->string('caption')->nullable();
            $t->timestampsTz();
            $t->index('point_id');
        });

        // Solicitudes del cliente (a una sala o a una zona) y la respuesta del supervisor.
        Schema::create('sellout.requests', function (Blueprint $t) {
            $this->std($t);
            $t->string('scope', 10);                                   // point | zone
            $t->uuid('point_id')->nullable();
            $t->string('zone_label')->nullable();
            $t->text('body');
            $t->string('status', 12)->default('pending');              // pending | attended | closed
            $t->text('response')->nullable();
            $t->string('response_photo')->nullable();
            $t->string('response_mime', 40)->nullable();
            $t->uuid('created_by');
            $t->uuid('responded_by')->nullable();
            $t->timestampTz('responded_at')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->jsonb('log')->default('[]');
            $t->timestampsTz();
            $t->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['requests', 'point_photos', 'point_notes', 'targets', 'sales', 'points', 'products', 'chains'] as $t) {
            Schema::dropIfExists("sellout.$t");
        }
        DB::statement('drop schema if exists sellout cascade');
    }
};
