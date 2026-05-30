<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builder_conversations', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // cnv_01j...
            $table->string('organization_id', 36)->nullable();
            $table->string('app_id', 36);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')
                ->on('apps')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_conversations');
    }
};
