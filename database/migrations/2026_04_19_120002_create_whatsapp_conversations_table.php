<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // ULID (no prefix, parity widget)
            $table->string('channel_id', 36);
            $table->string('contact_id', 36);
            $table->string('title')->nullable();
            $table->json('metadata')->nullable();
            $table->json('flow_state')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->string('status', 20)->default('pending');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_at')->nullable();
            $table->unsignedInteger('total_response_time_ms')->default(0);
            $table->boolean('is_resolved')->default(false);
            $table->boolean('is_abandoned')->default(false);
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamps();

            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();

            $table->index(['channel_id', 'created_at']);
            $table->index(['contact_id', 'created_at']);
            $table->index('status');
            $table->index(['assigned_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
