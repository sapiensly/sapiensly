<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('visibility')->default('private');
            $table->string('channel_type', 20); // ChannelType enum (widget|whatsapp|...)
            $table->string('name', 120);
            $table->string('agent_id', 36)->nullable();
            $table->string('agent_team_id', 36)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            // agent_id / agent_team_id intentionally unconstrained to match the
            // pattern used by chatbots (one XOR the other, nullable FKs managed
            // at app level).
            $table->index(['organization_id', 'visibility']);
            $table->index(['user_id', 'status']);
            $table->index(['channel_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
