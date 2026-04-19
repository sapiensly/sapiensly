<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_variables', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('integration_environment_id', 36);
            $table->string('key', 60);
            $table->text('value'); // encrypted:string — applied uniformly for secret + non-secret
            $table->boolean('is_secret')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->foreign('integration_environment_id')
                ->references('id')
                ->on('integration_environments')
                ->cascadeOnDelete();

            $table->unique(['integration_environment_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_variables');
    }
};
