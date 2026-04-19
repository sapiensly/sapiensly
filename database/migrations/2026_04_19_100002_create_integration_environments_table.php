<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_environments', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('integration_id', 36);
            $table->string('name', 60);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('integration_id')
                ->references('id')
                ->on('integrations')
                ->cascadeOnDelete();

            $table->unique(['integration_id', 'name']);
            $table->index(['integration_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_environments');
    }
};
