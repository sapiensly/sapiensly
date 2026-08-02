<?php

use App\Ai\Tools\Builder\ListAvailableComponentsTool;
use App\Services\Admin\ComponentCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The catalogue is derived, and that is the whole point of it.
 *
 * A block type already has to be declared in six places that agree, with the
 * manifest schema deciding which of them is right. An admin page listing them
 * by hand would be the seventh, and the first to drift — advertising a
 * component the platform would refuse to render, or hiding one that works.
 */
it('lists exactly the block types the schema accepts', function () {
    $schema = json_decode((string) file_get_contents(base_path('resources/schemas/app-manifest/v1.json')), true);

    $fromSchema = [];
    foreach ($schema['$defs']['block']['oneOf'] as $branch) {
        $def = $schema['$defs'][str_replace('#/$defs/', '', $branch['$ref'])];
        foreach ($def['allOf'] ?? [] as $part) {
            if (is_string($part['properties']['type']['const'] ?? null)) {
                $fromSchema[] = $part['properties']['type']['const'];
            }
        }
    }
    sort($fromSchema);

    $listed = collect(app(ComponentCatalog::class)->all()['apps'])->pluck('type')->sort()->values()->all();

    expect($listed)->toBe(array_values(array_unique($fromSchema)));
});

it('never offers a dashboard block a manifest would reject', function () {
    $catalog = app(ComponentCatalog::class)->all();
    $apps = collect($catalog['apps'])->pluck('type');

    expect($catalog['dashboards'])->not->toBeEmpty();
    foreach ($catalog['dashboards'] as $entry) {
        expect($apps)->toContain($entry['type']);
    }
});

it('describes each app block with what the builder model was told', function () {
    // Not a retelling: the same string, so the page cannot drift from the
    // instruction the model actually receives.
    $described = collect(ListAvailableComponentsTool::catalog())
        ->keyBy('type');

    foreach (app(ComponentCatalog::class)->all()['apps'] as $entry) {
        $expected = $described[$entry['type']]['description'] ?? '';
        expect($entry['description'])->toBe($expected);
    }
});

it('lists the flow nodes a conversation is built from', function () {
    $chat = app(ComponentCatalog::class)->all()['chat'];

    expect($chat)->not->toBeEmpty()
        ->and(collect($chat)->pluck('type'))->toContain('start', 'message');
});

it('is reachable by a sysadmin and nobody else', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs(mcpMember(mcpOrg()))->get('/admin/components')->assertForbidden();
    $this->actingAs(mcpSysadmin(mcpOrg()))->get('/admin/components')->assertOk();
});
