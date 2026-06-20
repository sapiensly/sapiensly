<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files a visitor uploads to a widget chatbot conversation (images, pdf, word,
 * md, txt, csv, json). Mirrors `chat_attachments`, but keyed to a
 * `widget_conversation` / `widget_message` and carrying `extracted_text` so the
 * bot flow + agents can read a document's content without re-parsing it.
 *
 * Tenant data — created here in `platform` (unqualified names resolve there on
 * the owner's search_path) and relocated to `tenant` + put under RLS by the
 * companion migration (2026_06_20_900001_move_widget_attachments_to_tenant),
 * exactly like the other widget runtime tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_attachments', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // watt_01j...
            $table->string('widget_conversation_id', 36);
            $table->string('widget_message_id', 36)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_id', 36)->nullable();
            $table->string('disk', 60)->default('local');
            $table->string('storage_path', 500);
            $table->string('original_name', 500);
            $table->string('mime', 200);
            $table->unsignedBigInteger('size_bytes');
            $table->string('kind', 20)->default('document'); // image | document | audio
            $table->longText('extracted_text')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index(['widget_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_attachments');
    }
};
