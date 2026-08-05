<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the closing critic first found nothing missing in this conversation's
 * app. Stamped by the builder's `critique_build` tool and read by the review
 * rail (BuilderAiService::continueForBuildCritic): a turn that applies changes
 * while this is NULL gets a platform-queued review turn.
 *
 * The rail exists because the failure is a model that believes it finished. A
 * "call critique_build before reporting done" rule is prompt-only, and the two
 * live builds this was written from both ended by narrating success over work
 * they had skipped. Once stamped, later tweak turns never force the review
 * again — the same retirement the landing gate uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            $table->timestampTz('build_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            $table->dropColumn('build_reviewed_at');
        });
    }
};
