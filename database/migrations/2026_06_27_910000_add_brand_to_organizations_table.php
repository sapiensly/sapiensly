<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The organization Brandbook: logo, icon, colours and font centralized on the
     * organization so every customizable surface (apps, chatbots) can inherit a
     * consistent brand. A single JSON bag — a small, evolving singleton per org.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('brand')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('brand');
        });
    }
};
