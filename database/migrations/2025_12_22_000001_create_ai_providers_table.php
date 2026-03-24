<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('visibility')->default('private');
            $table->string('name'); // e.g. "anthropic", "openai" (matches config key)
            $table->string('driver'); // e.g. "anthropic", "openai", "azure" (matches Lab enum value)
            $table->string('display_name'); // e.g. "Anthropic", "OpenAI" (human label)
            $table->text('credentials'); // encrypted JSON: api_key, url, api_version, etc.
            $table->json('models')->nullable(); // [{id, label, capabilities}]
            $table->boolean('is_default')->default(false);
            $table->boolean('is_default_embeddings')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['organization_id', 'visibility']);
            $table->index(['user_id', 'status']);

            // One provider per name per org
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
