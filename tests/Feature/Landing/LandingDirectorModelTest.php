<?php

use App\Models\AiCatalogModel;
use App\Services\Ai\AiDefaults;
use App\Services\Landing\LandingDesignCritic;

/**
 * The constructor/director model split: `landing_builder` is the model that
 * BUILDS a landing, `landing_director` the one that JUDGES it at the design
 * gate — each with a primary + backup in admin AI > Defaults. The director
 * chain inherits landing_builder → builder when no dedicated director is set,
 * so existing installs behave exactly as before the split.
 */
function seedDirectorCatalogModel(string $modelId): AiCatalogModel
{
    return AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => $modelId,
        'label' => $modelId,
        'capability' => 'chat',
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
}

it('judges on the landing_director chain when one is configured', function () {
    $defaults = app(AiDefaults::class);
    $defaults->setCatalogId('landing_director', 'primary', seedDirectorCatalogModel('director-model')->id);
    $defaults->setCatalogId('landing_director', 'fallback', seedDirectorCatalogModel('director-backup')->id);
    // A configured constructor must NOT leak into the director chain (the cap
    // is primary + one backup — each attempt can burn the full timeout).
    $defaults->setCatalogId('landing_builder', 'primary', seedDirectorCatalogModel('constructor-model')->id);

    expect(app(LandingDesignCritic::class)->directorCandidates())
        ->toBe(['director-model', 'director-backup']);
});

it('inherits the landing_builder chain when no dedicated director is set', function () {
    $defaults = app(AiDefaults::class);
    $defaults->setCatalogId('landing_builder', 'primary', seedDirectorCatalogModel('constructor-model')->id);
    $defaults->setCatalogId('landing_builder', 'fallback', seedDirectorCatalogModel('constructor-backup')->id);

    expect(app(LandingDesignCritic::class)->directorCandidates())
        ->toBe(['constructor-model', 'constructor-backup']);
});

it('falls back to the general builder chain when neither landing module is set', function () {
    expect(app(LandingDesignCritic::class)->directorCandidates())
        ->toBe([AiDefaults::HARD_DEFAULT]);
});

it('puts an explicit override first', function () {
    app(AiDefaults::class)->setCatalogId('landing_director', 'primary', seedDirectorCatalogModel('director-model')->id);

    expect(app(LandingDesignCritic::class)->directorCandidates('explicit-model'))
        ->toBe(['explicit-model', 'director-model']);
});

it('exposes landing_director as a configurable chat module', function () {
    expect(AiDefaults::MODULES)->toContain('landing_director')
        ->and(AiDefaults::CAPABILITY['landing_director'])->toBe('chat');
});
