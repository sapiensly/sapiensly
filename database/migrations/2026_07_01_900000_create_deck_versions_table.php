<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version history for presentations ("Living Decks"). Every write to a deck —
 * an edit, an automatic data refresh, a restore — records an immutable
 * snapshot here: `manifest` has the live data bindings RESOLVED AND BAKED so
 * any version opens faithfully without its sources; `source_manifest` keeps
 * the authored bindings for restore; `data_digest` is a compact map of each
 * live binding's values used to detect "did the data actually change" and to
 * write the AI change summary.
 *
 * Tenant data — created here in `platform` and relocated to `tenant` + RLS by
 * the companion migration (2026_07_01_900001_move_deck_versions_to_tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deck_versions', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // dkv_01j...
            // RLS tenant key, autofilled from the session GUCs on insert.
            $table->string('organization_id', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // References tenant.documents; same-schema FK added post-relocation
            // is skipped on purpose (mirrors records.app_id) — validated in code.
            $table->string('document_id', 36);
            $table->unsignedInteger('version_number');
            // create | edit | manual_refresh | scheduled_refresh | restore
            $table->string('cause', 30);
            $table->jsonb('manifest');
            $table->jsonb('source_manifest');
            $table->jsonb('data_digest')->nullable();
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->unique(['document_id', 'version_number']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_versions');
    }
};
