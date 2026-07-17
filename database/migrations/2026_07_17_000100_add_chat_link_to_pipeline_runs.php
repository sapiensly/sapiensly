<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Link an Express run back to the chat that launched it. When the general chat
 * autoroutes a "build me a dashboard" message into Express (ChatMessageController),
 * the build runs on the Builder surface but the chat needs to learn when it
 * finishes: ExpressDashboardJob updates the linked chat message ("…listo") and
 * rebroadcasts it. A run launched from the Builder itself leaves both null.
 *
 * Tenant table (already relocated + RLS'd) — add the columns in place with the
 * schema-qualified idiom the other pipeline_runs alter uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tenant.pipeline_runs ADD COLUMN IF NOT EXISTS chat_id varchar(255) NULL');
        DB::statement('ALTER TABLE tenant.pipeline_runs ADD COLUMN IF NOT EXISTS chat_message_id varchar(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant.pipeline_runs DROP COLUMN IF EXISTS chat_id');
        DB::statement('ALTER TABLE tenant.pipeline_runs DROP COLUMN IF EXISTS chat_message_id');
    }
};
