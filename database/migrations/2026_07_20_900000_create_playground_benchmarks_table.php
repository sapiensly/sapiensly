<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playground benchmarks: one prompt run against N models for side-by-side
 * comparison. The benchmark row is the light-weight group header (prompt,
 * capability, human decision); the heavy telemetry lives in the member
 * playground_runs, which point back via benchmark_id. Tenant data: relocated to
 * the tenant schema + RLS by the companion move migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playground_benchmarks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('organization_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('capability');
            /** The shared request input every member run received (prompt, ...). */
            $table->jsonb('input')->nullable();
            /** Repeats per model (>=2 → medians are compared instead of single shots). */
            $table->unsignedSmallInteger('repeats')->default(1);
            /** The human verdict: which member run won, and why. */
            $table->string('winner_run_id')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        // playground_runs already lives in the tenant schema — qualify it.
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->string('benchmark_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table(Schemas::qualify('playground_runs'), function (Blueprint $table) {
            $table->dropColumn('benchmark_id');
        });

        Schema::dropIfExists('playground_benchmarks');
    }
};
