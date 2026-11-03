<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El puntaje es opcional: una auditoría normalmente levanta información y detecta hallazgos, sin porcentaje.
        Schema::table('fieldaudit.templates', fn (Blueprint $t) => $t->boolean('scoring_enabled')->default(false));
        Schema::table('fieldaudit.audits', fn (Blueprint $t) => $t->boolean('scoring_enabled')->default(false));
    }

    public function down(): void
    {
        Schema::table('fieldaudit.audits', fn (Blueprint $t) => $t->dropColumn('scoring_enabled'));
        Schema::table('fieldaudit.templates', fn (Blueprint $t) => $t->dropColumn('scoring_enabled'));
    }
};
