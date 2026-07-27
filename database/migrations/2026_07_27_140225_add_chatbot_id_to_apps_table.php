<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chatbot a landing carries, denormalized out of `settings.chatbot.id` in
 * the active manifest and kept in sync on every version write.
 *
 * The manifest stays the source of truth — this column exists so the reverse
 * question is cheap: "which published landings serve this chatbot?" That
 * question is asked on the widget's origin check, on every request, and
 * answering it from the manifests would mean scanning `app_versions` JSON.
 *
 * Nullable and un-constrained by a foreign key on purpose: a chatbot deleted
 * out from under a landing must leave the page renderable (minus the bubble),
 * not cascade the app away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('chatbot_id', 36)->nullable()->after('kind');
            $table->index('chatbot_id');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropIndex(['chatbot_id']);
            $table->dropColumn('chatbot_id');
        });
    }
};
