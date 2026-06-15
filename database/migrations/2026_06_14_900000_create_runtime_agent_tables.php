<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the embedded runtime agent (builder power #3): a conversation an
 * end-user of a built app has with its agent, and the messages within it.
 *
 * Tenant data — these rows belong to the app's tenant and are relocated to the
 * `tenant` schema + put under RLS by the companion migration
 * (2026_06_14_900001_move_runtime_agent_to_tenant), which also adds the tenant
 * key columns. Created here in `platform` (unqualified names resolve there on the
 * owner's search_path), exactly like the other tenant tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_agent_conversations', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // rconv_01j...
            $table->string('app_id', 36);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
            $table->index(['app_id', 'created_at']);
        });

        Schema::create('runtime_agent_messages', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // rmsg_01j...
            $table->string('conversation_id', 36);
            $table->string('role'); // user | assistant
            $table->text('content')->nullable();
            // Forward-compat for the write slice's gate: a message can carry a
            // proposed action awaiting approval. Null for plain read turns.
            $table->string('message_type')->default('text'); // text | action_proposal | action_result
            $table->jsonb('action_payload')->nullable();
            $table->string('status')->default('none'); // none | streaming | error
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('runtime_agent_conversations')
                ->cascadeOnDelete();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_agent_messages');
        Schema::dropIfExists('runtime_agent_conversations');
    }
};
