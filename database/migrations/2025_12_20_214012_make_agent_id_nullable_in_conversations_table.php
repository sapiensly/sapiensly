<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Make agent_id nullable so team conversations can exist without
     * being tied to a specific agent.
     */
    public function up(): void
    {
        $connection = DB::connection()->getDriverName();

        if ($connection === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN agent_id DROP NOT NULL');
        }
        // SQLite was already handled in the earlier migration
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection()->getDriverName();

        if ($connection === 'pgsql') {
            // Only set NOT NULL if no null values exist
            DB::statement('ALTER TABLE conversations ALTER COLUMN agent_id SET NOT NULL');
        }
    }
};
