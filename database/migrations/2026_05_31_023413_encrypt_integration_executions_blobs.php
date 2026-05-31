<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make integration_executions store its sensitive blobs redacted + encrypted.
 *
 * The header columns were `json`; encrypted ciphertext is NOT valid JSON, so
 * Postgres would reject it — switch them to `text`. The body columns are
 * already longText and hold ciphertext fine.
 *
 * DEV decision (per spec): there is no plaintext backfill. We TRUNCATE the
 * table so no plaintext rows survive, then rely on the strict cast. This is
 * only safe because the table holds no production data yet — encryption MUST be
 * in place before this table ever writes a real row in prod.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('integration_executions')->truncate();

        Schema::table('integration_executions', function (Blueprint $table) {
            $table->dropColumn(['request_headers', 'response_headers']);
        });

        Schema::table('integration_executions', function (Blueprint $table) {
            $table->text('request_headers')->nullable()->after('url');
            $table->text('response_headers')->nullable()->after('response_status');
        });
    }

    public function down(): void
    {
        Schema::table('integration_executions', function (Blueprint $table) {
            $table->dropColumn(['request_headers', 'response_headers']);
        });

        Schema::table('integration_executions', function (Blueprint $table) {
            $table->json('request_headers')->nullable()->after('url');
            $table->json('response_headers')->nullable()->after('response_status');
        });
    }
};
