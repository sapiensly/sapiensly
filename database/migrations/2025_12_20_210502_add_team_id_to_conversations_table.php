<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->ulid('team_id')->nullable()->after('agent_id');
            $table->foreign('team_id')->references('id')->on('agent_teams')->cascadeOnDelete();
        });

        // Make agent_id nullable for team conversations
        // SQLite doesn't support ALTER COLUMN, so we need to use raw SQL for PostgreSQL
        // and recreate the table for SQLite (which happens during testing)
        $connection = DB::connection()->getDriverName();

        if ($connection === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN agent_id DROP NOT NULL');
        } elseif ($connection === 'sqlite') {
            // For SQLite, we need to recreate the foreign key as nullable
            // This is handled by disabling foreign keys temporarily
            DB::statement('PRAGMA foreign_keys=off');

            // Create a new table with nullable agent_id
            DB::statement('CREATE TABLE conversations_new (
                id VARCHAR(36) PRIMARY KEY,
                user_id INTEGER NOT NULL,
                agent_id VARCHAR(36) NULL,
                team_id VARCHAR(36) NULL,
                title VARCHAR(255) NULL,
                metadata TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                FOREIGN KEY (team_id) REFERENCES agent_teams(id) ON DELETE CASCADE
            )');

            // Copy data
            DB::statement('INSERT INTO conversations_new SELECT id, user_id, agent_id, team_id, title, metadata, created_at, updated_at FROM conversations');

            // Drop old table and rename new
            DB::statement('DROP TABLE conversations');
            DB::statement('ALTER TABLE conversations_new RENAME TO conversations');

            // Recreate indexes
            DB::statement('CREATE INDEX conversations_user_id_agent_id_index ON conversations(user_id, agent_id)');

            DB::statement('PRAGMA foreign_keys=on');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
