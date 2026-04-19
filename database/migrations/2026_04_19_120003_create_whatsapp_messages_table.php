<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('whatsapp_conversation_id', 36);
            $table->string('role', 20); // MessageRole enum
            $table->string('direction', 20); // MessageDirection enum
            $table->text('content');
            $table->string('content_type', 20)->default('text');
            $table->string('media_url', 2000)->nullable();
            $table->string('media_local_path', 500)->nullable();
            $table->string('media_mime', 100)->nullable();
            $table->string('template_name', 100)->nullable();
            $table->string('template_language', 10)->nullable();
            $table->string('wamid')->nullable()->unique();
            $table->string('provider_message_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('status_updates')->nullable();
            $table->unsignedSmallInteger('error_code')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->string('model', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();

            $table->foreign('whatsapp_conversation_id')
                ->references('id')
                ->on('whatsapp_conversations')
                ->cascadeOnDelete();

            $table->index(['whatsapp_conversation_id', 'created_at']);
            $table->index(['status', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
