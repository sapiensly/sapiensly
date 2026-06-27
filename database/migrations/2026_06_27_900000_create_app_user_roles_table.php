<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grants an organization member a role on a specific app. The runtime resolves a
 * user's effective app role from this table (`AppAccessResolver`); `open` apps
 * fall back to the manifest's default role, `allowlist` apps require a grant.
 *
 * Per-user, per-app grants are mutable tenant data, so they live here — NOT in
 * the immutable, versioned manifest. We store the role `slug` (stable across
 * manifest versions), never the regenerated role `id`.
 *
 * Tenant data — created here in `platform` (unqualified names resolve there on
 * the owner's search_path) and relocated to `tenant` + put under RLS by the
 * companion migration (2026_06_27_900001_move_app_user_roles_to_tenant), exactly
 * like the other tenant runtime tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_user_roles', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // aur_01j...
            // RLS tenant key (organization mode keys on organization_id; personal
            // mode keys on user_id). Autofilled from the session GUCs on insert.
            $table->string('organization_id', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // app_id references platform.apps; no cross-schema FK (mirrors records.app_id) — validated in app code.
            $table->string('app_id', 36);
            // The org member being granted, and the role slug from the manifest.
            $table->foreignId('assigned_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_slug', 60);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            // One role per user per app (single-role MVP).
            $table->unique(['app_id', 'assigned_user_id']);
            $table->index('app_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_roles');
    }
};
