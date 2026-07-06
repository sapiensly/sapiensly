<?php

use App\Ai\Tools\Builder\ListAvailableIconsTool;
use App\Support\Icons\IconCatalog;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The icon system has two halves that MUST agree: the AI-facing catalog
 * (IconCatalog, PHP) and the runtime renderer's EAGER registry (icons.ts).
 * This test is the contract that keeps them in sync, so the AI never emits a
 * curated name that silently fails to render.
 */
it('keeps the PHP curated catalog and the runtime EAGER registry in sync', function () {
    $icons = file_get_contents(base_path('resources/js/runtime/icons.ts'));

    // Slice the REGISTRY object and pull its keys (bare or quoted kebab-case).
    $start = strpos($icons, 'const REGISTRY');
    $end = strpos($icons, '};', $start);
    $registry = substr($icons, $start, $end - $start);
    preg_match_all("/^\s*'?([a-z][a-z0-9-]*)'?:\s*[A-Z]/m", $registry, $m);
    $registryNames = $m[1];

    sort($registryNames);
    $catalog = IconCatalog::NAMES;
    sort($catalog);

    expect($registryNames)->toBe($catalog);
});

it('list_available_icons returns the curated shortlist, not the full set', function () {
    $result = json_decode((new ListAvailableIconsTool)->handle(new ToolRequest([])), true);

    expect($result['count'])->toBe(count(IconCatalog::NAMES))
        ->and($result['icons'])->toContain('shopping-cart', 'user', 'trash', 'trending-up');
});

it('ALL_NAMES is a superset of the curated catalog', function () {
    expect(array_diff(IconCatalog::NAMES, IconCatalog::ALL_NAMES))->toBe([]);
});

/** The full validity set must not silently drift stale after a @lucide/vue upgrade. */
it('ALL_NAMES matches every real Lucide icon module on disk', function () {
    $dir = base_path('node_modules/@lucide/vue/dist/esm/icons');
    if (! is_dir($dir)) {
        $this->markTestSkipped('@lucide/vue not installed in this environment.');
    }

    $real = collect(scandir($dir))
        ->filter(fn (string $f): bool => str_ends_with($f, '.mjs') && ! str_ends_with($f, '.mjs.map'))
        ->map(fn (string $f): string => substr($f, 0, -4))
        ->sort()->values()->all();

    expect(array_diff($real, IconCatalog::ALL_NAMES))->toBe([]);
});
