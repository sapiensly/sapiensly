<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Add user_id for standalone agents
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            // Make agent_team_id nullable for standalone agents
            $table->foreignId('agent_team_id')->nullable()->change();

            // Drop the unique constraint that required agent_team_id
            $table->dropUnique(['agent_team_id', 'type']);

            // Add index for standalone agent queries
            $table->index(['user_id', 'type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type', 'status']);
            $table->unique(['agent_team_id', 'type']);
            $table->foreignId('agent_team_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
