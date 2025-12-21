<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('widget_conversation_id');

            $table->string('role');
            $table->text('content');
            $table->integer('tokens_used')->nullable();
            $table->string('model')->nullable();
            $table->json('metadata')->nullable();

            // Analytics
            $table->integer('response_time_ms')->nullable();

            $table->timestamps();

            $table->foreign('widget_conversation_id')
                ->references('id')
                ->on('widget_conversations')
                ->cascadeOnDelete();

            $table->index(['widget_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_messages');
    }
};
