<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A WhatsApp connection owns its Bot Flow too — the same roster-driven design
     * as an AI Bot. A flow belongs to exactly one surface (chatbot OR connection).
     */
    public function up(): void
    {
        Schema::table('bot_flows', function (Blueprint $table) {
            $table->string('whatsapp_connection_id', 36)->nullable()->after('chatbot_id');

            $table->foreign('whatsapp_connection_id')
                ->references('id')
                ->on('whatsapp_connections')
                ->nullOnDelete();

            $table->unique('whatsapp_connection_id');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flows', function (Blueprint $table) {
            $table->dropForeign(['whatsapp_connection_id']);
            $table->dropUnique(['whatsapp_connection_id']);
            $table->dropColumn('whatsapp_connection_id');
        });
    }
};
