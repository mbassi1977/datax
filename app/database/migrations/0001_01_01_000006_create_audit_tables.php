<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit.audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->uuid('tenant_id')->nullable()->index();
            $t->uuid('user_id')->nullable()->index();
            $t->string('action', 50);          // created, updated, deleted, login, export...
            $t->string('entity_type', 100)->nullable();
            $t->uuid('entity_id')->nullable();
            $t->jsonb('old_values')->nullable();
            $t->jsonb('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit.audit_events');
    }
};
