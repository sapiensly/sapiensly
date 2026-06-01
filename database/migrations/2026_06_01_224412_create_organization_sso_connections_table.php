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
        Schema::create('organization_sso_connections', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('organization_id', 36);
            $table->boolean('enabled')->default(false);
            $table->boolean('auto_provision')->default(true);
            $table->string('issuer')->nullable();
            $table->string('client_id')->nullable();
            $table->text('config')->nullable();
            $table->json('allowed_domains')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->unique('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_sso_connections');
    }
};
