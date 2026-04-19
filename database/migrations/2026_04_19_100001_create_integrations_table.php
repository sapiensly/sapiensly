<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('visibility')->default('private');
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('base_url', 500);
            $table->string('auth_type', 30)->default('none');
            $table->text('auth_config'); // encrypted:array
            $table->json('default_headers')->nullable();
            // No FK on active_environment_id — circular reference with integration_environments.
            // Validated at app level via IntegrationEnvironment belongsTo Integration.
            $table->string('active_environment_id', 36)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->string('color', 7)->nullable();
            $table->string('icon', 50)->nullable();
            $table->boolean('allow_insecure_tls')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['organization_id', 'visibility']);
            $table->index(['user_id', 'status']);
            $table->unique(['organization_id', 'slug']);
            $table->index(['visibility', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
