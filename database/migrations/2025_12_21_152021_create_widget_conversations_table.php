<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('chatbot_id', 36);
            $table->string('widget_session_id', 36);

            $table->string('title')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('message_count')->default(0);

            // Feedback
            $table->tinyInteger('rating')->nullable();
            $table->text('feedback')->nullable();

            // Analytics fields
            $table->timestamp('first_response_at')->nullable();
            $table->integer('total_response_time_ms')->default(0);
            $table->boolean('is_resolved')->default(false);
            $table->boolean('is_abandoned')->default(false);
            $table->timestamp('abandoned_at')->nullable();

            $table->timestamps();

            $table->foreign('chatbot_id')->references('id')->on('chatbots')->cascadeOnDelete();
            $table->foreign('widget_session_id')->references('id')->on('widget_sessions')->cascadeOnDelete();

            $table->index(['chatbot_id', 'created_at']);
            $table->index('widget_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_conversations');
    }
};
