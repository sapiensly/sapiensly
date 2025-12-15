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
        Schema::create('tool_group_items', function (Blueprint $table) {
            $table->id();
            $table->string('tool_group_id', 36);
            $table->string('tool_id', 36);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('tool_group_id')
                ->references('id')
                ->on('tools')
                ->cascadeOnDelete();

            $table->foreign('tool_id')
                ->references('id')
                ->on('tools')
                ->cascadeOnDelete();

            $table->unique(['tool_group_id', 'tool_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_group_items');
    }
};
