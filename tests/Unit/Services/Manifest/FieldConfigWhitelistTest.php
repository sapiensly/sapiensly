<?php

use App\Services\Manifest\AppScaffolder;

/**
 * The typed `add_field` path must be able to express every field property the
 * manifest schema allows.
 *
 * When it cannot, nothing fails: `config` is validated as a bare array, the
 * unknown key is filtered out by the whitelist, a new version is saved and the
 * tool reports success — so the caller is told a scannable SKU was created and
 * gets a plain text box. That is how `capture` went missing on both types that
 * take it, and `display` on the two that render differently because of it.
 *
 * This is a lock, not a snapshot: it derives both sides and compares them, so a
 * property added to the schema tomorrow fails here until the whitelist grows.
 */
function schemaFieldProps(): array
{
    // Resolved from this file rather than resource_path(): a unit test has no
    // booted application to ask.
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 4).'/resources/schemas/app-manifest/v1.json'),
        true,
    );

    $defs = $schema['$defs'];
    $base = array_keys($defs['field_base']['properties'] ?? []);

    $out = [];
    foreach ($defs as $name => $definition) {
        // `field_condition` is the visible_if/required_if comparison, not a type.
        if (! str_starts_with($name, 'field_') || in_array($name, ['field_base', 'field_condition'], true)) {
            continue;
        }

        $props = [];
        foreach ($definition['allOf'] ?? [$definition] as $part) {
            $props = array_merge($props, array_keys($part['properties'] ?? []));
        }
        $props = array_merge($props, array_keys($definition['properties'] ?? []));

        $out[substr($name, strlen('field_'))] = array_values(array_diff(
            array_unique($props),
            $base,
            // Set by the scaffolder itself, not carried in `config`.
            ['id', 'slug', 'name', 'type', 'description'],
            // `options` has its own top-level add_field parameter; `derived_rate`
            // is written by the profiler from sampled rows, never by a caller.
            ['options', 'derived_rate'],
        ));
    }

    return $out;
}

it('lets a typed add_field carry every property the schema allows', function () {
    $reflection = new ReflectionClass(AppScaffolder::class);
    $base = $reflection->getConstant('BASE_OPTIONAL_PROPS');
    $perType = $reflection->getConstant('FIELD_CONFIG_PROPS');

    $missing = [];
    foreach (schemaFieldProps() as $type => $props) {
        $allowed = array_merge($base, $perType[$type] ?? []);
        $gap = array_values(array_diff($props, $allowed));
        if ($gap !== []) {
            $missing[$type] = $gap;
        }
    }

    expect($missing)->toBe([], 'Dropped silently by the config whitelist: '.json_encode($missing));
});

it('keeps the mobile-capture props reachable, since they are what the catalog sells', function () {
    $perType = (new ReflectionClass(AppScaffolder::class))->getConstant('FIELD_CONFIG_PROPS');

    expect($perType['string'])->toContain('capture')
        ->and($perType['file'])->toContain('capture')
        // A boolean rendered as a toggle and a select rendered as radios are
        // both read straight off `display` by the runtime form input.
        ->and($perType['boolean'])->toContain('display')
        ->and($perType['single_select'])->toContain('display');
});
