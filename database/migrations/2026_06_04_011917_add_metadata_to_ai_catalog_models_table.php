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
        Schema::table('ai_catalog_models', function (Blueprint $table) {
            $table->unsignedInteger('context_window')->nullable()->after('capability');
            $table->decimal('input_price_per_mtok', 12, 6)->nullable()->after('context_window');
            $table->decimal('output_price_per_mtok', 12, 6)->nullable()->after('input_price_per_mtok');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_catalog_models', function (Blueprint $table) {
            $table->dropColumn(['context_window', 'input_price_per_mtok', 'output_price_per_mtok']);
        });
    }
};
