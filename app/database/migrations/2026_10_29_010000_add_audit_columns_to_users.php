<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security.users', function (Blueprint $t) {
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('security.users', fn (Blueprint $t) => $t->dropColumn(['created_by', 'updated_by']));
    }
};
