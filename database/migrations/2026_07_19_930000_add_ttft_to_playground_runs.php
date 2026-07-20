<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time-to-first-token: milliseconds from request send to the first streamed
 * content (or reasoning) delta. Only the streamed capabilities (text / coding)
 * can measure it; blocking calls leave it null. It is captured at runtime, not
 * derivable from the stored row, so it needs its own column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->unsignedInteger('ttft_ms')->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->dropColumn('ttft_ms');
        });
    }
};
