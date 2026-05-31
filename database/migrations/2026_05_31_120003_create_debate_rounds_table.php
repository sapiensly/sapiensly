<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debate_rounds', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('debate_id', 36);
            $table->unsignedTinyInteger('round_number');
            $table->string('type')->default('opening');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('consensus_score')->nullable();
            $table->json('consensus_summary')->nullable();
            $table->boolean('consensus_reached')->default(false);
            $table->timestamps();

            $table->foreign('debate_id')->references('id')->on('debates')->cascadeOnDelete();
            $table->index(['debate_id', 'round_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debate_rounds');
    }
};
