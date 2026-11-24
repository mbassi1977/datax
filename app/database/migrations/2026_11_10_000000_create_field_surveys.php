<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Levantamiento = campaña: una plantilla publicada que se reparte entre varios locales y colectores.
        // Cada local genera una auditoría (fieldaudit.audits) con su copia de la plantilla.
        Schema::create('fieldaudit.surveys', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->uuid('template_id')->nullable();
            $t->foreign('template_id')->references('id')->on('fieldaudit.templates')->nullOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('status', 20)->default('active');       // active, closed
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['tenant_id', 'status']);
        });

        Schema::table('fieldaudit.audits', function (Blueprint $t) {
            $t->uuid('survey_id')->nullable()->index();
            $t->foreign('survey_id')->references('id')->on('fieldaudit.surveys')->nullOnDelete();
        });

        // Operaciones recibidas desde dispositivos sin conexión: el op_id hace que reenviar sea inocuo.
        Schema::create('fieldaudit.sync_ops', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->uuid('user_id');
            $t->string('op_id', 64);
            $t->uuid('audit_id')->nullable();
            $t->string('type', 20);
            $t->string('outcome', 20);                          // ok, rejected
            $t->smallInteger('http_status')->default(200);
            $t->jsonb('result')->nullable();
            $t->timestampTz('device_at')->nullable();
            $t->timestampsTz();
            $t->unique(['user_id', 'op_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fieldaudit.sync_ops');
        Schema::table('fieldaudit.audits', fn (Blueprint $t) => $t->dropColumn('survey_id'));
        Schema::dropIfExists('fieldaudit.surveys');
    }
};
