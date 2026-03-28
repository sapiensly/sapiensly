<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->jsonb('flow_state')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->dropColumn('flow_state');
        });
    }
};
