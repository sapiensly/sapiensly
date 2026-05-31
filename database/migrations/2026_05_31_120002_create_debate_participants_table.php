<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debate_participants', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('debate_id', 36);
            $table->string('model');
            $table->string('provider')->nullable();
            $table->string('display_name');
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('accent')->nullable();
            $table->string('final_stance')->nullable();
            $table->timestamps();

            $table->foreign('debate_id')->references('id')->on('debates')->cascadeOnDelete();
            $table->index(['debate_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debate_participants');
    }
};
