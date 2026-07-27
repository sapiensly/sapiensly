<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives widget conversations the same two columns WhatsApp conversations have
 * had all along: `status` and `assigned_user_id`.
 *
 * The asymmetry was the whole bug. `ConversationStatus::suppressesAutoReply()`
 * already means "a human has this, stay quiet", and the WhatsApp job already
 * asks — but the widget had nowhere to record the answer, so its `human_handoff`
 * node wrote a metadata key nobody read and the bot kept talking over the
 * escalation. Same columns, same names, same semantics: a takeover written once
 * works on both channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->after('title');
            $table->foreignId('assigned_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();

            $table->index('status');
            $table->index(['assigned_user_id', 'status']);
        });

        // Existing rows already carry the two booleans the status subsumes, so
        // read the state off them rather than parking every past conversation
        // in a default that contradicts its own flags.
        DB::table('widget_conversations')->where('is_resolved', true)->update(['status' => 'resolved']);
        DB::table('widget_conversations')->where('is_abandoned', true)->update(['status' => 'abandoned']);
    }

    public function down(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropIndex(['assigned_user_id', 'status']);
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'assigned_user_id']);
        });
    }
};
