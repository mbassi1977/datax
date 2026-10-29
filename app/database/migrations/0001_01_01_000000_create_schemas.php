<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $schemas = ['core', 'security', 'master', 'audit'];

    public function up(): void
    {
        DB::statement('create extension if not exists pgcrypto');
        foreach ($this->schemas as $schema) {
            DB::statement("create schema if not exists {$schema}");
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->schemas) as $schema) {
            DB::statement("drop schema if exists {$schema} cascade");
        }
    }
};
