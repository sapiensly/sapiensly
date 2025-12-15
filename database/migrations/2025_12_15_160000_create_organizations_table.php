<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('workos_organization_id')->unique();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('workos_organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
