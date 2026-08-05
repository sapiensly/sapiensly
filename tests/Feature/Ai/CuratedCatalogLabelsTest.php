<?php

use App\Models\AiCatalogModel;
use App\Services\AiProviderService;

/**
 * The repair half of the label fix: syncDirectCatalogModels stopped flattening
 * curated names, but the rows already flattened stayed that way — a picker
 * reading "Claude Opus 4.8" next to "gpt-5.5". These cover the migration that
 * gives the names back without touching anything an admin wrote.
 */
it('gives a sync-flattened row its curated name back', function () {
    $model = AiProviderService::MODEL_CATALOGS['openai'][0];

    AiCatalogModel::query()
        ->where('driver', 'openai')
        ->where('model_id', $model['id'])
        ->update(['label' => $model['id']]);

    (require database_path('migrations/2026_08_05_900000_restore_curated_catalog_labels.php'))->up();

    expect(AiCatalogModel::query()->where('model_id', $model['id'])->first()->label)
        ->toBe($model['label']);
});

it('leaves a label an admin wrote alone', function () {
    $model = AiProviderService::MODEL_CATALOGS['openai'][0];

    AiCatalogModel::query()
        ->where('driver', 'openai')
        ->where('model_id', $model['id'])
        ->update(['label' => 'The fast one']);

    (require database_path('migrations/2026_08_05_900000_restore_curated_catalog_labels.php'))->up();

    expect(AiCatalogModel::query()->where('model_id', $model['id'])->first()->label)
        ->toBe('The fast one');
});

it('leaves a model the bootstrap does not name as it found it', function () {
    // Synced live but absent from the curated list: a bare id beats a wrong one.
    AiCatalogModel::query()->create([
        'driver' => 'openai',
        'model_id' => 'gpt-not-in-the-bootstrap',
        'capability' => 'chat',
        'label' => 'gpt-not-in-the-bootstrap',
    ]);

    (require database_path('migrations/2026_08_05_900000_restore_curated_catalog_labels.php'))->up();

    expect(AiCatalogModel::query()->where('model_id', 'gpt-not-in-the-bootstrap')->first()->label)
        ->toBe('gpt-not-in-the-bootstrap');
});

it('relabels every capability row of the same model', function () {
    // A vision-capable chat model owns two rows; the picker can surface either.
    $model = AiProviderService::MODEL_CATALOGS['openai'][0];

    AiCatalogModel::query()
        ->where('driver', 'openai')
        ->where('model_id', $model['id'])
        ->update(['label' => $model['id']]);

    (require database_path('migrations/2026_08_05_900000_restore_curated_catalog_labels.php'))->up();

    $labels = AiCatalogModel::query()
        ->where('model_id', $model['id'])
        ->pluck('label')
        ->unique();

    expect($labels->all())->toBe([$model['label']]);
});
