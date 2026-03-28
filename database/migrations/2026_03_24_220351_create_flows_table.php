<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('agent_id', 36)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->string('visibility')->default('private');
            $table->jsonb('definition')->default('{}');
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->foreign('agent_id')
                ->references('id')
                ->on('agents')
                ->cascadeOnDelete();

            $table->index(['user_id', 'status']);
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
