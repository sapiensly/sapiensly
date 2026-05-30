<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builder_messages', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // bmsg_01j...
            $table->string('conversation_id', 36);
            $table->string('role'); // user, assistant
            $table->text('content')->nullable();
            // proposed_patch: RFC 6902 ops array. Null for plain chat turns.
            $table->jsonb('proposed_patch')->nullable();
            $table->text('change_summary')->nullable();
            $table->string('status')->default('pending'); // pending | applied | rejected | none
            $table->string('applied_version_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('builder_conversations')
                ->cascadeOnDelete();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_messages');
    }
};
