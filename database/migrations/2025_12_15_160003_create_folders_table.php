<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('parent_id', 36)->nullable();
            $table->string('name');
            $table->string('visibility')->default('private');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['user_id', 'visibility']);
            $table->index(['organization_id', 'visibility']);
            $table->index('parent_id');
        });

        // Self-referencing foreign key must be added after table creation
        Schema::table('folders', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('folders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        Schema::dropIfExists('folders');
    }
};
