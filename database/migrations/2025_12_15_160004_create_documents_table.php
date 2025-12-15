<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('folder_id', 36)->nullable();
            $table->string('name');
            $table->string('type'); // pdf, txt, docx, md, csv, json
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('visibility')->default('private');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->foreign('folder_id')
                ->references('id')
                ->on('folders')
                ->nullOnDelete();

            $table->index(['user_id', 'visibility']);
            $table->index(['organization_id', 'visibility']);
            $table->index('folder_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
