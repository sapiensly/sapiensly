<?php

use App\Services\Manifest\ManifestDiffService;

function manifestV1(): array
{
    return [
        'version' => 1,
        'objects' => [
            ['id' => 'obj_ideas', 'slug' => 'ideas', 'name' => 'Ideas', 'fields' => [
                ['id' => 'fld_title', 'slug' => 'title', 'name' => 'Title', 'type' => 'string'],
                ['id' => 'fld_status', 'slug' => 'status', 'name' => 'Status', 'type' => 'single_select', 'options' => [
                    ['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'],
                ]],
            ]],
        ],
        'pages' => [
            ['id' => 'pag_ideas', 'slug' => 'ideas', 'name' => 'Ideas', 'path' => '/ideas', 'blocks' => [
                ['id' => 'blk_1', 'type' => 'heading'],
                ['id' => 'blk_2', 'type' => 'table'],
            ]],
        ],
        'permissions' => ['roles' => [
            ['id' => 'rol_admin', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
            ['id' => 'rol_user', 'slug' => 'user', 'name' => 'User', 'is_default' => true],
        ]],
        'settings' => ['default_locale' => 'en', 'default_currency' => 'USD'],
    ];
}

it('reports objects, fields, pages, roles and settings added/removed/modified', function () {
    $to = manifestV1();
    // Add option 'c' to status; add a number field; change title type.
    $to['objects'][0]['fields'][1]['options'][] = ['value' => 'c', 'label' => 'C'];
    $to['objects'][0]['fields'][0]['type'] = 'long_text';
    $to['objects'][0]['fields'][] = ['id' => 'fld_priority', 'slug' => 'priority', 'name' => 'Priority', 'type' => 'number'];
    // Add a whole object with 2 fields.
    $to['objects'][] = ['id' => 'obj_tags', 'slug' => 'tags', 'name' => 'Tags', 'fields' => [
        ['id' => 'fld_tname', 'slug' => 'name', 'type' => 'string'],
        ['id' => 'fld_color', 'slug' => 'color', 'type' => 'string'],
    ]];
    // Add a block to the ideas page; add a page.
    $to['pages'][0]['blocks'][] = ['id' => 'blk_3', 'type' => 'chart'];
    $to['pages'][] = ['id' => 'pag_tags', 'slug' => 'tags', 'name' => 'Tags', 'path' => '/tags', 'blocks' => []];
    // Add a role; change a setting.
    $to['permissions']['roles'][] = ['id' => 'rol_editor', 'slug' => 'editor', 'name' => 'Editor', 'is_default' => false];
    $to['settings']['default_locale'] = 'es';

    $diff = app(ManifestDiffService::class)->diff(manifestV1(), $to);

    expect($diff['summary'])->toMatchArray([
        'objects_added' => 1,
        'objects_modified' => 1,
        'fields_added' => 3,   // priority + the 2 fields of the new object
        'fields_modified' => 2, // title type + status options
        'pages_added' => 1,
        'pages_modified' => 1,
        'roles_added' => 1,
        'settings_changed' => 1,
    ]);

    expect($diff['objects']['added'][0]['slug'])->toBe('tags');

    $ideas = $diff['objects']['modified'][0];
    expect($ideas['slug'])->toBe('ideas');
    expect(collect($ideas['fields']['added'])->pluck('slug'))->toContain('priority');
    $statusChange = collect($ideas['fields']['modified'])->firstWhere('slug', 'status');
    expect($statusChange['changes']['options']['added'])->toBe(['c']);
    $titleChange = collect($ideas['fields']['modified'])->firstWhere('slug', 'title');
    expect($titleChange['changes']['type'])->toBe(['from' => 'string', 'to' => 'long_text']);

    expect($diff['pages']['modified'][0])->toMatchArray(['slug' => 'ideas', 'blocks_added' => 1]);
    expect($diff['pages']['added'][0]['slug'])->toBe('tags');
    expect($diff['roles']['added'][0]['slug'])->toBe('editor');
    expect($diff['settings'])->toBe([['key' => 'default_locale', 'from' => 'en', 'to' => 'es']]);
});

it('detects removals and renames', function () {
    $to = manifestV1();
    $to['objects'][0]['name'] = 'Concepts';          // rename object
    array_pop($to['objects'][0]['fields']);           // remove the status field
    array_pop($to['pages'][0]['blocks']);             // remove a block

    $diff = app(ManifestDiffService::class)->diff(manifestV1(), $to);

    expect($diff['summary'])->toMatchArray([
        'objects_modified' => 1,
        'fields_removed' => 1,
        'pages_modified' => 1,
    ]);
    $ideas = $diff['objects']['modified'][0];
    expect($ideas['renamed']['name'])->toBe(['from' => 'Ideas', 'to' => 'Concepts']);
    expect($ideas['fields']['removed'][0]['slug'])->toBe('status');
    expect($diff['pages']['modified'][0]['blocks_removed'])->toBe(1);
});

it('returns an empty diff for identical manifests', function () {
    $diff = app(ManifestDiffService::class)->diff(manifestV1(), manifestV1());

    expect($diff['summary'])->toBe([]);
    expect($diff['objects'])->toBe([]);
    expect($diff['pages'])->toBe([]);
    expect($diff['settings'])->toBe([]);
});
