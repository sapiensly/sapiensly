<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only run on PostgreSQL - SQLite doesn't support pgvector
        // We check the actual connection driver at runtime
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Pin to `public` so both runtime roles can use the vector type and
            // operators (the schemas/roles migration already does this; kept here
            // so the migration set stands alone on a fresh database).
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector SCHEMA public');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP EXTENSION IF EXISTS vector');
        }
    }
};
