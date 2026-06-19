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
        Schema::table('builder_messages', function (Blueprint $table) {
            // Provisioning proposal for a draft integration created mid-build:
            // {integration_id, name, auth_type, authorize_required, authorized,
            // reason, actions[]}. Rendered as a card whose Connect button sends
            // the user to authorize in the provider's own surface. Null when the
            // turn provisioned nothing.
            $table->jsonb('integration_proposal')->nullable()->after('plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('builder_messages', function (Blueprint $table) {
            $table->dropColumn('integration_proposal');
        });
    }
};
