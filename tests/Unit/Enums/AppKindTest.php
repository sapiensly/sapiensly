<?php

use App\Enums\AppKind;

function manifestWith(array $pages): array
{
    return ['id' => 'app_x', 'slug' => 's', 'name' => 'N', 'version' => 1, 'objects' => [], 'pages' => $pages];
}

it('classifies a page of charts and KPIs as a dashboard', function () {
    $manifest = manifestWith([[
        'id' => 'pag_dash', 'slug' => 'd', 'name' => 'D', 'path' => '/', 'blocks' => [
            ['id' => 'mg_kpis', 'type' => 'metric_grid', 'items' => []],
            ['id' => 'ch_trend', 'type' => 'chart', 'chart_type' => 'line'],
        ],
    ]]);

    expect(AppKind::classify($manifest))->toBe(AppKind::Dashboard);
});

it('classifies a chart nested inside a container as a dashboard', function () {
    $manifest = manifestWith([[
        'id' => 'pag_dash', 'slug' => 'd', 'name' => 'D', 'path' => '/', 'blocks' => [
            ['id' => 'cn_row', 'type' => 'container', 'blocks' => [
                ['id' => 'ch_a', 'type' => 'chart', 'chart_type' => 'bar'],
            ]],
        ],
    ]]);

    expect(AppKind::classify($manifest))->toBe(AppKind::Dashboard);
});

it('classifies anything with a data-entry form as an app, even alongside charts', function () {
    $manifest = manifestWith([[
        'id' => 'pag_app', 'slug' => 'a', 'name' => 'A', 'path' => '/', 'blocks' => [
            ['id' => 'ch_a', 'type' => 'chart', 'chart_type' => 'bar'],
            ['id' => 'fm_new', 'type' => 'form', 'object_id' => 'obj_x', 'mode' => 'create'],
        ],
    ]]);

    expect(AppKind::classify($manifest))->toBe(AppKind::App);
});

it('classifies an editable data_grid as an app', function () {
    $manifest = manifestWith([[
        'id' => 'pag_app', 'slug' => 'a', 'name' => 'A', 'path' => '/', 'blocks' => [
            ['id' => 'dg_edit', 'type' => 'data_grid', 'data_source' => ['object_id' => 'obj_x'], 'columns' => []],
        ],
    ]]);

    expect(AppKind::classify($manifest))->toBe(AppKind::App);
});

it('classifies a website (hero + feature_grid, no analytics) as an app', function () {
    $manifest = manifestWith([[
        'id' => 'pag_home', 'slug' => 'h', 'name' => 'H', 'path' => '/', 'blocks' => [
            ['id' => 'hro_hero', 'type' => 'hero', 'title' => 'Hi'],
            ['id' => 'fg_feats', 'type' => 'feature_grid', 'items' => []],
        ],
    ]]);

    expect(AppKind::classify($manifest))->toBe(AppKind::App);
});

it('classifies an empty app as an app', function () {
    expect(AppKind::classify(manifestWith([])))->toBe(AppKind::App);
});

it('classifies a manifest with settings.surface=landing as a landing', function () {
    $manifest = manifestWith([[
        'id' => 'pag_home', 'slug' => 'h', 'name' => 'H', 'path' => '/', 'blocks' => [
            ['id' => 'hro_hero', 'type' => 'hero', 'title' => 'Hi'],
            ['id' => 'fg_feats', 'type' => 'feature_grid', 'items' => []],
        ],
    ]]);
    $manifest['settings'] = ['surface' => 'landing'];

    expect(AppKind::classify($manifest))->toBe(AppKind::Landing);
});

it('lets an explicit surface override content inference', function () {
    // A form alone would classify as App; an explicit surface wins.
    $manifest = manifestWith([[
        'id' => 'pag_x', 'slug' => 'x', 'name' => 'X', 'path' => '/', 'blocks' => [
            ['id' => 'fm_new', 'type' => 'form', 'object_id' => 'obj_x', 'mode' => 'create'],
        ],
    ]]);
    $manifest['settings'] = ['surface' => 'dashboard'];

    expect(AppKind::classify($manifest))->toBe(AppKind::Dashboard);
});

it('ignores an unknown surface value and falls back to content inference', function () {
    $manifest = manifestWith([[
        'id' => 'pag_d', 'slug' => 'd', 'name' => 'D', 'path' => '/', 'blocks' => [
            ['id' => 'ch_a', 'type' => 'chart', 'chart_type' => 'bar'],
        ],
    ]]);
    $manifest['settings'] = ['surface' => 'bogus'];

    expect(AppKind::classify($manifest))->toBe(AppKind::Dashboard);
});
