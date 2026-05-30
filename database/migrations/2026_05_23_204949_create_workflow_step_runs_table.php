<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_step_runs', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // wstep_01j...
            $table->string('run_id', 36);
            $table->string('step_id', 36);
            $table->string('step_type');
            $table->string('status')->default('pending'); // pending | running | completed | failed | skipped
            $table->unsignedSmallInteger('sequence_index');
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')
                ->references('id')
                ->on('workflow_runs')
                ->cascadeOnDelete();

            $table->index(['run_id', 'sequence_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_runs');
    }
};
