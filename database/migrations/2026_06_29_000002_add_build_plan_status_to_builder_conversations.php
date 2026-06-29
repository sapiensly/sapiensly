<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            // Derived, indexed mirror of build_plan.status ('active' | 'done' |
            // 'abandoned'; null when no plan). Lets an operator / cron find
            // conversations with an unfinished plan to resume — cheaply, without
            // scanning jsonb — via the withActivePlan() scope.
            $table->string('build_plan_status')
                ->storedAs("build_plan->>'status'")
                ->nullable()
                ->after('build_plan');
            $table->index('build_plan_status');
        });
    }

    public function down(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            $table->dropIndex(['build_plan_status']);
            $table->dropColumn('build_plan_status');
        });
    }
};
