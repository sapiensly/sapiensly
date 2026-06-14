<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agent authorship and action-proposal payloads on chat messages.
 *
 * - agent_id: platform.agents id of the agent that authored the message
 *   (null = user / system / plain assistant). Plain column, no cross-schema FK,
 *   mirroring chats.agent_id.
 * - message_type: 'text' (default) | 'action_proposal' | 'action_result'.
 * - agent_data_context: snapshot of the data sources/values an agent used when
 *   composing the message (rendered as data pills).
 * - action_payload: the synthesized action descriptor (only for action_proposal).
 *
 * `chat_messages` is already a tenant-schema table, so this only adds columns.
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

        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS agent_id varchar(36)");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS message_type varchar(20) NOT NULL DEFAULT 'text'");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS agent_data_context jsonb");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS action_payload jsonb");
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('chat_messages');

        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS agent_id");
        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS message_type");
        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS agent_data_context");
        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS action_payload");
    }
};
