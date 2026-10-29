<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // last_name pasa a ser el apellido paterno; el materno va aparte.
        Schema::table('master.people', fn (Blueprint $t) => $t->string('second_last_name')->nullable()->after('last_name'));
        Schema::table('integrations.import_jobs', fn (Blueprint $t) => $t->jsonb('warnings')->nullable());
    }

    public function down(): void
    {
        Schema::table('integrations.import_jobs', fn (Blueprint $t) => $t->dropColumn('warnings'));
        Schema::table('master.people', fn (Blueprint $t) => $t->dropColumn('second_last_name'));
    }
};
