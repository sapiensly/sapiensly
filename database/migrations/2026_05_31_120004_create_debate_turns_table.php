<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debate_turns', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('debate_id', 36);
            $table->string('debate_round_id', 36);
            $table->string('debate_participant_id', 36)->nullable();
            $table->string('role')->default('participant');
            $table->string('model')->nullable();
            $table->longText('content')->nullable();
            $table->text('stance_summary')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('debate_id')->references('id')->on('debates')->cascadeOnDelete();
            $table->foreign('debate_round_id')->references('id')->on('debate_rounds')->cascadeOnDelete();
            $table->foreign('debate_participant_id')->references('id')->on('debate_participants')->nullOnDelete();
            $table->index(['debate_round_id']);
            $table->index(['debate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debate_turns');
    }
};
