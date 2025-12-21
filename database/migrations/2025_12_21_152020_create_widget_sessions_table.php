<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_sessions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('chatbot_id', 36);
            $table->string('session_token', 64)->unique();

            // Optional visitor info
            $table->string('visitor_email')->nullable();
            $table->string('visitor_name')->nullable();
            $table->json('visitor_metadata')->nullable();

            // Session tracking
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('referrer_url')->nullable();
            $table->text('page_url')->nullable();

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->foreign('chatbot_id')->references('id')->on('chatbots')->cascadeOnDelete();
            $table->index('session_token');
            $table->index(['chatbot_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_sessions');
    }
};
