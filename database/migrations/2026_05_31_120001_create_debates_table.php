<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debates', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('title')->nullable();
            $table->longText('topic');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('max_rounds')->default(3);
            $table->unsignedTinyInteger('current_round')->default(0);
            $table->string('moderator_model');
            $table->boolean('consensus_reached')->default(false);
            $table->unsignedTinyInteger('consensus_score')->nullable();
            $table->json('settings')->nullable();
            $table->string('visibility')->default('private');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index(['user_id', 'last_activity_at']);
            $table->index(['organization_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debates');
    }
};
