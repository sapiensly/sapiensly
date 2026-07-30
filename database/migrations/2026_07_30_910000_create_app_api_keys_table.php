<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys for an app's REST data API.
 *
 * PLATFORM schema, not tenant, and that is forced by the auth flow rather than
 * chosen: a request arrives carrying only a bearer token, so the key must be
 * readable BEFORE any tenant scope exists — looking it up is what tells us which
 * tenant to bind. Isolation here is structural (platform_app reaches only this
 * schema), the same way the `apps` table itself is isolated.
 *
 * The token is never stored. Only its SHA-256 hash is kept, so a leak of this
 * table does not hand anyone a working credential, and the plaintext is shown
 * exactly once at creation.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->create('app_api_keys', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('app_id');
            $table->string('organization_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->string('name');
            // The visible half: enough to tell two keys apart in a list and to
            // match a key to a log line, useless on its own.
            $table->string('prefix', 16);
            $table->string('token_hash', 64)->unique();

            // The app role this key acts as — its CEILING. Scopes below can only
            // narrow what the role already allows, never widen it.
            $table->string('role_slug');
            // {"objects": {"<slug>": ["read","create","update","delete"]}} or
            // {"objects": "*"} — the per-key grant, intersected with the role.
            $table->json('scopes')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('app_api_keys');
    }
};
