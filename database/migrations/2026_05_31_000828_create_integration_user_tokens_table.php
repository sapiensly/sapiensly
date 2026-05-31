<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integration_user_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('integration_id', 36);
            $table->text('auth_config'); // encrypted:array — access/refresh tokens, expires_at
            $table->timestamps();

            $table->foreign('integration_id')
                ->references('id')
                ->on('integrations')
                ->cascadeOnDelete();

            $table->unique(['user_id', 'integration_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_user_tokens');
    }
};
