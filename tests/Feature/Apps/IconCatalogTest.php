<?php

use App\Ai\Tools\Builder\ListAvailableIconsTool;
use App\Support\Icons\IconCatalog;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The icon system has two halves that MUST agree: the AI-facing catalog
 * (IconCatalog, PHP) and the runtime renderer's registry (icons.ts). This test
 * is the contract that keeps them in sync, so the AI never emits a name that
 * silently fails to render.
 */
it('keeps the PHP catalog and the runtime registry in sync', function () {
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

it('list_available_icons returns the catalog', function () {
    $result = json_decode((new ListAvailableIconsTool)->handle(new ToolRequest([])), true);

    expect($result['count'])->toBe(count(IconCatalog::NAMES))
        ->and($result['icons'])->toContain('shopping-cart', 'user', 'trash', 'trending-up');
});
