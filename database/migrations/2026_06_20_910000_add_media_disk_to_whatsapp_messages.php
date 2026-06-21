<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Persist the disk name a WhatsApp media file was written to, so the reply
 * orchestrator can round-trip back to the same disk (re-registering a
 * CloudProvider disk if needed) and hand an image to a vision model. Without it
 * only the path was stored, leaving the disk ambiguous across processes.
 *
 * `whatsapp_messages` already lives in the `tenant` schema (it is a tenant
 * table), so the column is added there directly. Runs as the owner.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE tenant.whatsapp_messages ADD COLUMN IF NOT EXISTS media_disk varchar(60)');
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE tenant.whatsapp_messages DROP COLUMN IF EXISTS media_disk');
    }
};
