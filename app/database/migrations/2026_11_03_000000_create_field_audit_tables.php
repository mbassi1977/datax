<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create schema if not exists fieldaudit');

        // Plantillas: la estructura (secciones y preguntas) vive en un documento JSON validado por el servidor.
        Schema::create('fieldaudit.templates', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('status', 20)->default('draft');      // draft, published, archived
            $t->integer('version')->default(1);
            $t->integer('pass_threshold')->default(80);       // % mínimo para aprobar
            $t->jsonb('structure');
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['tenant_id', 'status']);
        });

        // Auditoría asignada a un auditor para un local/empresa. Guarda una copia de la plantilla (snapshot):
        // editar la plantilla después no altera las auditorías ya creadas.
        Schema::create('fieldaudit.audits', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->uuid('template_id')->nullable();
            $t->foreign('template_id')->references('id')->on('fieldaudit.templates')->nullOnDelete();
            $t->string('template_name');
            $t->integer('template_version');
            $t->integer('pass_threshold')->default(80);
            $t->jsonb('structure');
            $t->string('title');
            $t->foreignUuid('organization_id')->constrained('core.organizations');
            $t->uuid('branch_id')->nullable();
            $t->foreign('branch_id')->references('id')->on('core.branches');
            $t->uuid('project_id')->nullable();
            $t->foreign('project_id')->references('id')->on('master.projects');
            $t->foreignUuid('auditor_id')->constrained('security.users');
            $t->date('scheduled_for')->nullable();
            $t->date('due_on')->nullable();
            $t->string('status', 20)->default('scheduled');   // scheduled, in_progress, submitted, approved, returned, cancelled
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_notes')->nullable();
            $t->decimal('score', 5, 1)->nullable();
            $t->string('result', 10)->nullable();             // pass, fail
            $t->integer('critical_failures')->default(0);
            $t->decimal('started_lat', 10, 7)->nullable();
            $t->decimal('started_lng', 10, 7)->nullable();
            $t->decimal('submitted_lat', 10, 7)->nullable();
            $t->decimal('submitted_lng', 10, 7)->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['tenant_id', 'status']);
            $t->index(['auditor_id', 'status']);
        });

        // Una respuesta por pregunta. client_updated_at permite sincronizar desde un dispositivo sin conexión (gana la más reciente).
        Schema::create('fieldaudit.answers', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('audit_id')->constrained('fieldaudit.audits')->cascadeOnDelete();
            $t->string('question_id', 40);
            $t->jsonb('value')->nullable();
            $t->text('comment')->nullable();
            $t->timestampTz('client_updated_at')->nullable();
            $t->uuid('answered_by')->nullable();
            $t->timestampsTz();
            $t->unique(['audit_id', 'question_id']);
        });

        // Evidencias (fotos, firmas) con ubicación y hora de captura. client_id evita duplicados al reintentar una subida.
        Schema::create('fieldaudit.evidences', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('audit_id')->constrained('fieldaudit.audits')->cascadeOnDelete();
            $t->string('question_id', 40)->nullable();
            $t->string('kind', 20)->default('photo');          // photo, signature
            $t->string('path');
            $t->string('mime', 50);
            $t->integer('size')->default(0);
            $t->string('caption')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestampTz('taken_at')->nullable();
            $t->string('client_id', 64)->nullable();
            $t->uuid('uploaded_by')->nullable();
            $t->timestampsTz();
            $t->index(['audit_id', 'question_id']);
        });
        DB::statement('create unique index evidences_client on fieldaudit.evidences (audit_id, client_id) where client_id is not null');

        // Hallazgos con su plan de acción: se generan solos al enviar la auditoría (preguntas falladas) o a mano.
        Schema::create('fieldaudit.findings', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->foreignUuid('audit_id')->constrained('fieldaudit.audits')->cascadeOnDelete();
            $t->string('question_id', 40)->nullable();
            $t->string('section_title')->nullable();
            $t->text('question_text')->nullable();
            $t->string('severity', 10)->default('medium');     // low, medium, high, critical
            $t->text('description');
            $t->string('source', 10)->default('auto');         // auto, manual
            $t->string('status', 20)->default('open');         // open, in_progress, closed
            $t->uuid('responsible_person_id')->nullable();
            $t->foreign('responsible_person_id')->references('id')->on('master.people');
            $t->text('action_plan')->nullable();
            $t->date('due_on')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['findings', 'evidences', 'answers', 'audits', 'templates'] as $table) {
            Schema::dropIfExists("fieldaudit.$table");
        }
    }
};
