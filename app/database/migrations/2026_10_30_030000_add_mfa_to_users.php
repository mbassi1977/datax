<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security.users', function (Blueprint $t) {
            $t->text('mfa_secret')->nullable();               // cifrado con la clave de la aplicación
            $t->timestampTz('mfa_enabled_at')->nullable();     // nulo mientras el alta está pendiente
            $t->jsonb('mfa_recovery_codes')->nullable();       // solo hashes
            $t->bigInteger('mfa_last_step')->nullable();       // último intervalo TOTP usado: evita reutilizar un código
        });
    }

    public function down(): void
    {
        Schema::table('security.users', fn (Blueprint $t) => $t->dropColumn(['mfa_secret', 'mfa_enabled_at', 'mfa_recovery_codes', 'mfa_last_step']));
    }
};
