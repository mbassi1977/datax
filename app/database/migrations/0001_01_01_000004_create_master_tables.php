<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master.people', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->nullable()->constrained('core.organizations');
            $t->foreignUuid('area_id')->nullable()->constrained('core.areas');
            $t->uuid('supervisor_id')->nullable();
            $t->string('rut', 20)->nullable();
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->date('birth_date')->nullable();
            $t->string('sex', 20)->nullable();
            $t->string('nationality')->nullable();
            $t->string('job_title')->nullable();
            $t->jsonb('roles')->default('[]'); // trabajador, consultor, relator, evaluador...
            $t->string('status', 20)->default('active');
            $t->timestampsTz();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->softDeletesTz();
            $t->unique(['tenant_id', 'rut']);
        });

        Schema::table('master.people', fn (Blueprint $t) => $t->foreign('supervisor_id')->references('id')->on('master.people'));

        Schema::create('master.projects', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations'); // cliente / empresa dueña
            $t->foreignUuid('cost_center_id')->nullable()->constrained('core.cost_centers');
            $t->foreignUuid('manager_id')->nullable()->constrained('master.people');
            $t->string('code', 30)->nullable();
            $t->string('name');
            $t->text('description')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('status', 20)->default('planned');
            $t->timestampsTz();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->softDeletesTz();
            $t->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master.projects');
        Schema::dropIfExists('master.people');
    }
};
