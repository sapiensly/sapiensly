<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * consultation_context: the agents an assistant turn consulted mid-stream (the
 * "ask another agent" feature). Each entry is { id, agent_id, agent_name,
 * question, answer, visible }, so the consultation cards survive a reload (the
 * live view comes from the ChatAgentConsultation broadcast). chat_messages is
 * already a tenant-schema table, so this only adds a column.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('chat_messages');

        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS consultation_context jsonb");
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('chat_messages');

        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS consultation_context");
    }
};
