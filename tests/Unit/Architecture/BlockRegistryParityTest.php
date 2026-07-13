<?php

/**
 * A block type has to be declared in SIX places, and nothing checked that they
 * agreed. Miss one and it fails in a way tests never see: absent from the Vue
 * registry it renders "unsupported"; absent from DATA_BLOCK_TYPES it renders with
 * `data === undefined` while its payload is still loading; absent from the
 * authoring catalog the model never learns it exists and simply never emits it;
 * absent from the planner's KNOWN_BLOCKS the dashboard planner rejects a plan
 * that uses it.
 *
 * The schema is the source of truth — it is the thing that decides whether a
 * manifest is legal. Everything else must know exactly what it knows.
 */
function blockTypesInSchema(): array
{
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/resources/schemas/app-manifest/v1.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $types = [];
    foreach ($schema['$defs']['block']['oneOf'] as $branch) {
        $name = str_replace('#/$defs/', '', $branch['$ref']);
        $def = $schema['$defs'][$name];
        // The discriminator lives as a `const` inside the second allOf branch.
        foreach ($def['allOf'] ?? [] as $part) {
            $const = $part['properties']['type']['const'] ?? null;
            if (is_string($const)) {
                $types[] = $const;
            }
        }
    }

    sort($types);

    return array_values(array_unique($types));
}

function sourceOf(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);
}

it('every block the schema allows can actually be rendered', function () {
    $renderer = sourceOf('resources/js/runtime/AppRenderer.vue');

    // The registry map: `type: BlockComponent,` between componentForType's braces.
    preg_match('/const componentForType = \{(.*?)\n\};/s', $renderer, $m);
    preg_match_all('/^\s{4}([a-z_]+):\s/m', $m[1] ?? '', $keys);
    $registered = $keys[1] ?? [];

    $missing = array_diff(blockTypesInSchema(), $registered);

    expect(array_values($missing))->toBe([]);
});

it('every data-bound block waits for its data instead of rendering without it', function () {
    $renderer = sourceOf('resources/js/runtime/AppRenderer.vue');
    preg_match('/const DATA_BLOCK_TYPES = new Set\(\[(.*?)\]\);/s', $renderer, $m);
    preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $keys);
    $awaited = $keys[1] ?? [];

    // A block that carries a data_source or a query has a payload to wait for.
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/resources/schemas/app-manifest/v1.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $dataBound = [];
    foreach ($schema['$defs'] as $name => $def) {
        if (! str_starts_with($name, 'block_')) {
            continue;
        }
        foreach ($def['allOf'] ?? [] as $part) {
            $props = $part['properties'] ?? [];
            $type = $props['type']['const'] ?? null;
            if (is_string($type) && (isset($props['data_source']) || isset($props['query']))) {
                $dataBound[] = $type;
            }
        }
    }

    // These carry a data_source but resolve client-side or hold nested blocks;
    // they were never in the Set and are not what this guards.
    $clientSide = ['funnel', 'hero', 'flow', 'form', 'multi_step_form', 'related_list', 'filter_bar', 'table'];

    $missing = array_diff($dataBound, $awaited, $clientSide);

    expect(array_values($missing))->toBe([]);
});

it('every block the schema allows is one the model is told about', function () {
    $catalog = sourceOf('app/Ai/Tools/Builder/ListAvailableComponentsTool.php');
    preg_match_all("/'type' => '([a-z_]+)'/", $catalog, $m);
    $described = $m[1] ?? [];

    // A model cannot emit a block it has never heard of — a type missing here is
    // dead weight in the schema, not a feature.
    $missing = array_diff(blockTypesInSchema(), $described);

    expect(array_values($missing))->toBe([]);
});

it('the runtime type union knows every block the schema allows', function () {
    $types = sourceOf('resources/js/runtime/types/manifest.ts');

    // A block is typed either by its own interface (`type: 'table';`) or as a
    // member of the catch-all union (`| 'heatmap'`). Both count; neither is
    // optional, because `block.type === 'pivot'` is a TS error without one.
    preg_match_all("/\|\s*'([a-z_]+)'/", $types, $union);
    preg_match_all("/^\s*type: '([a-z_]+)';/m", $types, $dedicated);
    $declared = array_unique([...($union[1] ?? []), ...($dedicated[1] ?? [])]);

    $missing = array_diff(blockTypesInSchema(), $declared);

    expect(array_values($missing))->toBe([]);
});
