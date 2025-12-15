<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_tools', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 36);
            $table->string('tool_id', 36);
            $table->timestamps();

            $table->foreign('agent_id')
                ->references('id')
                ->on('agents')
                ->cascadeOnDelete();

            $table->foreign('tool_id')
                ->references('id')
                ->on('tools')
                ->cascadeOnDelete();

            $table->unique(['agent_id', 'tool_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_tools');
    }
};
