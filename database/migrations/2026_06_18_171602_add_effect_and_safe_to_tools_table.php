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
        Schema::table('tools', function (Blueprint $table) {
            // Author-confirmed effect override. Null means "derive it from the
            // tool type / HTTP method / read_only flag" in ConnectorActionResolver.
            $table->string('effect')->nullable()->after('config');

            // Only a `safe` write action may run without an approval gate
            // (propose-don't-mutate). Defaults to false: writes are gated.
            $table->boolean('safe')->default(false)->after('effect');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropColumn(['effect', 'safe']);
        });
    }
};
