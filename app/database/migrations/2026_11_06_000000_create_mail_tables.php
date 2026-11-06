<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enlaces de restablecimiento / invitación: solo se guarda el hash del token; uno vigente por usuario.
        Schema::create('security.password_resets', function (Blueprint $t) {
            $t->foreignUuid('user_id')->primary()->constrained('security.users')->cascadeOnDelete();
            $t->string('token_hash', 64);
            $t->string('purpose', 20)->default('reset');   // reset | invitation
            $t->timestampTz('expires_at');
            $t->timestampTz('created_at')->useCurrent();
        });

        // Bitácora de correos salientes (sin el cuerpo: solo qué, a quién y si salió).
        Schema::create('audit.mail_log', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->foreignUuid('tenant_id')->constrained('core.tenants');
            $t->string('to_email');
            $t->string('template', 50);
            $t->string('subject');
            $t->string('status', 20)->default('queued');   // queued | sent | failed
            $t->text('error')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('sent_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit.mail_log');
        Schema::dropIfExists('security.password_resets');
    }
};
