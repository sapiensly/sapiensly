<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-agent (@mention) thread state on the chat.
 *
 * - mode: 'single' (default, ordinary chat) | 'multi_agent' (a @mention thread).
 * - synthesis_status: the action-close lifecycle —
 *   null | 'pending' | 'ready' | 'executed' | 'dismissed'.
 *
 * `chats` is already a tenant-schema table, so this only adds columns (idempotent).
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('chats');

        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS mode varchar(20) NOT NULL DEFAULT 'single'");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS synthesis_status varchar(20)");
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('chats');

        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS mode");
        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS synthesis_status");
    }
};
