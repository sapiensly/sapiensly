<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_executions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('integration_id', 36);
            $table->string('integration_request_id', 36)->nullable();
            $table->string('environment_id', 36)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('method', 10);
            $table->string('url', 2000);
            $table->json('request_headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->unsignedInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->unsignedBigInteger('response_size_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->default(false);
            $table->string('error_message', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('integration_id')
                ->references('id')
                ->on('integrations')
                ->cascadeOnDelete();

            $table->foreign('integration_request_id')
                ->references('id')
                ->on('integration_requests')
                ->nullOnDelete();

            $table->foreign('environment_id')
                ->references('id')
                ->on('integration_environments')
                ->nullOnDelete();

            $table->index(['integration_id', 'created_at']);
            $table->index(['integration_request_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_executions');
    }
};
