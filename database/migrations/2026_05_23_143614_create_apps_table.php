<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // app_01J...
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color', 7)->nullable();
            // current_version_id has no FK constraint to avoid circular reference
            // with app_versions.app_id. Integrity is enforced at the service layer.
            $table->string('current_version_id', 36)->nullable();
            $table->string('visibility')->default('private');
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->unique(['organization_id', 'slug']);
            $table->index(['user_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
