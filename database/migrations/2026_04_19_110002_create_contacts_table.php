<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('channel_id', 36);
            $table->string('identifier', 120); // wa_id for WhatsApp, session_token for widget
            $table->string('profile_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_e164', 25)->nullable();
            $table->string('locale', 10)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('channel_id')
                ->references('id')
                ->on('channels')
                ->cascadeOnDelete();

            $table->unique(['channel_id', 'identifier']);
            $table->index('phone_e164');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
