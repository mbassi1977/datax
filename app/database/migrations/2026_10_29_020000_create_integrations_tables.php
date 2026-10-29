<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create schema if not exists integrations');

        Schema::create('integrations.import_templates', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->string('name');
            $t->string('target', 50);
            $t->jsonb('mapping');           // campo HRX => nombre de la columna del archivo
            $t->jsonb('options')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'target', 'name']);
        });

        Schema::create('integrations.import_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->uuid('user_id');
            $t->string('target', 50);
            $t->string('original_name');
            $t->string('stored_path')->nullable();   // se elimina al terminar: no se conservan datos personales en disco
            $t->string('status', 20)->default('uploaded'); // uploaded, simulated, completed, rejected, failed
            $t->integer('total_rows')->default(0);
            $t->integer('created_count')->default(0);
            $t->integer('updated_count')->default(0);
            $t->integer('skipped_count')->default(0);
            $t->integer('error_count')->default(0);
            $t->jsonb('errors')->nullable();         // [{row, field, message}] (máx. 500)
            $t->jsonb('mapping')->nullable();
            $t->jsonb('options')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations.import_jobs');
        Schema::dropIfExists('integrations.import_templates');
    }
};
