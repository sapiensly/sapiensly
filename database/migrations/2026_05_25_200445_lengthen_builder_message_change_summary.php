<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The change_summary column was provisioned as varchar(255) — fine for
     * single-line summaries from early prototypes, but the accumulated turn
     * summary (multiple propose_change calls joined with " · ") routinely
     * blows past that. Promoting it to text removes the cap and avoids the
     * recursive "value too long for character varying(255)" error where the
     * subsequent attempt to log the failure as a content string also exceeds
     * 255 because the SQL it embedded was already over the limit.
     */
    public function up(): void
    {
        // Schema::table()->text() can't ALTER a column to text directly in
        // every Laravel/Postgres combo without doctrine/dbal. Drop straight to
        // SQL — it's a single column type change.
        DB::statement('ALTER TABLE builder_messages ALTER COLUMN change_summary TYPE text');
    }

    public function down(): void
    {
        // 4000 chars is still way longer than the old 255 cap but signals
        // that the column is intentionally bounded if anyone reads the schema.
        DB::statement('ALTER TABLE builder_messages ALTER COLUMN change_summary TYPE varchar(4000) USING substr(change_summary, 1, 4000)');
    }
};
