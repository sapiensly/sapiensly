<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organization Contextbook: the minimum business knowledge every model
 * interaction inside an organization should carry (identity, market, voice,
 * glossary, offerings, boundaries). Control-plane config, 1:1 with an
 * organization — mirroring organization_ai_budgets — so it stays in the
 * `platform` schema with no RLS: isolation there is structural.
 *
 * A dedicated table rather than a JSON column on `organizations` (the shape the
 * Brandbook took): `compiled_prompt` can run to kilobytes and must not ride
 * along on every Organization select, and this row needs its own updated_at /
 * updated_by — editing it changes the behaviour of every agent in the org at
 * once, so who touched it and when is auditable information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_ai_contexts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('organization_id', 36)->unique();
            // The structured fields, in the canonical vocabulary of
            // App\Support\Context\OrganizationContext. Every field is optional.
            $table->json('profile')->nullable();
            // The rendered prompt block + its token estimate, materialized on
            // write so no request ever pays to recompile it.
            $table->text('compiled_prompt')->nullable();
            $table->unsignedInteger('compiled_tokens')->nullable();
            // Lets an org keep its Contextbook on file but stop injecting it,
            // without deleting the content.
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by_id')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_ai_contexts');
    }
};
