<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Records the actual ingestion outcome per document↔knowledge-base link: the USD
 * cost (OCR + embeddings), the extraction method (php|ocr) and the page count.
 * Lets the UI show the real cost of each document. `document_knowledge_base` is a
 * tenant/RLS table; columns are added in place (owner connection).
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('document_knowledge_base');

        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS ingestion_cost numeric(12,6)");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS extraction_method varchar(16)");
        DB::statement("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS page_count integer");
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $qualified = Schemas::qualify('document_knowledge_base');

        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS ingestion_cost");
        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS extraction_method");
        DB::statement("ALTER TABLE {$qualified} DROP COLUMN IF EXISTS page_count");
    }
};
