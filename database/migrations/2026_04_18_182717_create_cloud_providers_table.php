<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_providers', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('visibility')->default('private');
            $table->string('kind'); // 'storage' | 'database'
            $table->string('driver'); // 's3' | 'r2' | 'minio' | 'digitalocean_spaces' | 'postgresql'
            $table->string('display_name');
            $table->text('credentials'); // encrypted JSON
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['organization_id', 'kind', 'visibility']);
            $table->index(['visibility', 'kind', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_providers');
    }
};
