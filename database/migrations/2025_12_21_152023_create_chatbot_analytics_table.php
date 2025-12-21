<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_analytics', function (Blueprint $table) {
            $table->id();
            $table->string('chatbot_id', 36);
            $table->date('date');
            $table->string('hour', 2)->nullable();

            // Volume metrics
            $table->integer('total_conversations')->default(0);
            $table->integer('total_messages')->default(0);
            $table->integer('unique_visitors')->default(0);

            // Performance metrics
            $table->integer('avg_response_time_ms')->default(0);
            $table->decimal('avg_rating', 2, 1)->nullable();
            $table->integer('total_ratings')->default(0);

            // Outcome metrics
            $table->integer('resolved_count')->default(0);
            $table->integer('abandoned_count')->default(0);
            $table->decimal('resolution_rate', 5, 2)->default(0);

            $table->timestamps();

            $table->foreign('chatbot_id')->references('id')->on('chatbots')->cascadeOnDelete();
            $table->unique(['chatbot_id', 'date', 'hour']);
            $table->index(['chatbot_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_analytics');
    }
};
