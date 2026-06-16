<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization AI spend budgets (phase 2). Platform/control-plane config
 * (1:1 with an organization), mirroring organization_sso_connection. Drives the
 * spend guard: system spend is capped by the org's budget AND the platform's
 * hard ceiling; own (BYOK) spend is capped only if the org opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_ai_budgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('organization_id', 36)->unique();
            // Monthly budgets in USD. Null = no cap for that source.
            $table->decimal('system_monthly_budget', 12, 2)->nullable();
            $table->decimal('own_monthly_budget', 12, 2)->nullable();
            // Platform-imposed hard ceiling on system spend (set by sysadmin) —
            // wins over the org's own system budget.
            $table->decimal('platform_system_cap', 12, 2)->nullable();
            $table->unsignedTinyInteger('alert_threshold_pct')->default(80);
            $table->unsignedTinyInteger('reset_day')->default(1); // day of month the budget period resets
            $table->boolean('enforcement_enabled')->default(true);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_ai_budgets');
    }
};
