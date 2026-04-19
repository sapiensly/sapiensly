<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_requests', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('integration_id', 36);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('folder', 100)->nullable();
            $table->string('method', 10);
            $table->string('path', 1000);
            $table->json('query_params')->nullable();
            $table->json('headers')->nullable();
            $table->string('body_type', 20)->nullable();
            $table->longText('body_content')->nullable();
            $table->unsignedInteger('timeout_ms')->default(30000);
            $table->boolean('follow_redirects')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('integration_id')
                ->references('id')
                ->on('integrations')
                ->cascadeOnDelete();

            $table->index(['integration_id', 'sort_order']);
            $table->index(['integration_id', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_requests');
    }
};
