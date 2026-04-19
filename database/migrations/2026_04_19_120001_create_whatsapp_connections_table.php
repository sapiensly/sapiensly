<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('channel_id', 36)->unique();
            $table->string('display_phone_number', 25);
            $table->string('phone_number_id', 40)->unique();
            $table->string('business_account_id', 40);
            $table->string('provider', 20)->default('meta_cloud');
            $table->text('auth_config'); // encrypted:array
            $table->string('webhook_verify_token', 100); // cleartext for fast GET verify path
            $table->string('messaging_tier', 20)->nullable();
            $table->boolean('allow_insecure_tls')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_webhook_received_at')->nullable();
            $table->timestamps();

            $table->foreign('channel_id')
                ->references('id')
                ->on('channels')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
