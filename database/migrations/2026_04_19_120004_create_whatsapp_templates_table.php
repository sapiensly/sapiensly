<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('whatsapp_connection_id', 36);
            $table->string('name', 100);
            $table->string('language', 10);
            $table->string('category', 30);
            $table->json('components');
            $table->string('status', 20)->default('unknown');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('whatsapp_connection_id')
                ->references('id')
                ->on('whatsapp_connections')
                ->cascadeOnDelete();

            $table->unique(['whatsapp_connection_id', 'name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
