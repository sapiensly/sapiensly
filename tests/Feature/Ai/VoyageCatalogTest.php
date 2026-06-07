<?php

use App\Models\AiCatalogModel;

/**
 * Voyage has no /models endpoint to sync from, so its catalog is curated.
 * These guard that the curated lineup stays current — the create-table seed
 * inserts MODEL_CATALOGS and the backfill migration prunes the deprecated rows.
 */
test('the Voyage catalog exposes the current lineup', function () {
    $ids = AiCatalogModel::query()
        ->where('driver', 'voyageai')
        ->where('capability', 'embeddings')
        ->pluck('model_id')
        ->all();

    expect($ids)->toContain(
        'voyage-4-large',
        'voyage-4',
        'voyage-4-lite',
        'voyage-4-nano',
        'voyage-code-3',
        'voyage-finance-2',
        'voyage-law-2',
    );
});

test('the deprecated Voyage models are gone from the catalog', function () {
    $ids = AiCatalogModel::query()
        ->where('driver', 'voyageai')
        ->pluck('model_id')
        ->all();

    expect($ids)->not->toContain('voyage-3')
        ->and($ids)->not->toContain('voyage-3-lite');
});

test('the backfill migration prunes a stale deprecated row on an existing install', function () {
    // Simulate a pre-existing install that still carries voyage-3-lite.
    AiCatalogModel::query()->updateOrCreate(
        ['driver' => 'voyageai', 'model_id' => 'voyage-3-lite', 'capability' => 'embeddings'],
        ['label' => 'Voyage 3 Lite', 'is_enabled' => true, 'sort_order' => 0],
    );

    $migration = require database_path('migrations/2026_06_06_235950_refresh_voyageai_catalog_models.php');
    $migration->up();

    expect(AiCatalogModel::query()->where('model_id', 'voyage-3-lite')->exists())->toBeFalse()
        ->and(AiCatalogModel::query()->where('model_id', 'voyage-4-lite')->exists())->toBeTrue();
});
