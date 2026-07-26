<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the design gate first returned ship:true for this conversation's
     * landing. Stamped by the builder's critique_landing_design tool and read
     * by the ship:true rail (BuilderAiService::continueForLandingGate): a
     * landing turn that applies changes while this is NULL gets a platform-
     * queued gate turn — prompt rules alone proved insufficient (observed
     * live: a build finished with one unshipped gate round). Once stamped,
     * later tweak turns never force the gate again.
     */
    public function up(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            $table->timestampTz('landing_shipped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builder_conversations', function (Blueprint $table) {
            $table->dropColumn('landing_shipped_at');
        });
    }
};
