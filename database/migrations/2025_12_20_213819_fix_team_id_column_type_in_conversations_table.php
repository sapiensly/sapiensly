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
     * Fix team_id column type from char(26) to varchar(36) to accommodate
     * prefixed ULIDs like "team_01kcy..." which are 31 characters.
     */
    public function up(): void
    {
        $connection = DB::connection()->getDriverName();

        if ($connection === 'pgsql') {
            // Drop foreign key, alter column type, recreate foreign key
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropForeign(['team_id']);
            });

            DB::statement('ALTER TABLE conversations ALTER COLUMN team_id TYPE VARCHAR(36)');

            Schema::table('conversations', function (Blueprint $table) {
                $table->foreign('team_id')->references('id')->on('agent_teams')->cascadeOnDelete();
            });
        }
        // SQLite already uses VARCHAR(36) from the previous migration's table recreation
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse - the wider column type is fine
    }
};
