<?php

use App\Services\AiProviderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give back the curated display names the catalog sync flattened.
 *
 * OpenAI-style listings carry no display name, so the sync's "label" for those
 * models is the bare id — and it used to write that through on every refresh.
 * The result is a picker where Anthropic reads "Claude Opus 4.8" and OpenAI
 * reads "gpt-5.5". syncDirectCatalogModels no longer overwrites a label with
 * the id, which is what makes this repair stick rather than survive until the
 * next refresh.
 *
 * Only rows whose label IS their id are touched: that means "never had a real
 * name", so an admin's own wording is never clobbered. Models the bootstrap
 * doesn't name are left alone — a bare id beats a wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (AiProviderService::MODEL_CATALOGS as $driver => $models) {
            foreach ($models as $model) {
                $id = (string) ($model['id'] ?? '');
                $label = (string) ($model['label'] ?? '');

                if ($id === '' || $label === '' || $label === $id) {
                    continue;
                }

                DB::table('ai_catalog_models')
                    ->where('driver', $driver)
                    ->where('model_id', $id)
                    // The whole condition: an untouched, sync-written label.
                    ->whereColumn('label', 'model_id')
                    ->update(['label' => $label]);
            }
        }
    }

    public function down(): void
    {
        // Reverting would put the bare ids back, which is the state this exists
        // to end. Nothing to roll back.
    }
};
