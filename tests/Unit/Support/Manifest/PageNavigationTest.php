<?php

use App\Support\Manifest\PageNavigation;

it('keeps a collection/list page in the navigation', function () {
    $page = ['slug' => 'productos', 'blocks' => [
        ['type' => 'heading', 'content' => 'Productos'],
        ['type' => 'table', 'data_source' => ['object_id' => 'obj_x']],
    ]];

    expect(PageNavigation::isNavigable($page))->toBeTrue();
});

it('keeps the dashboard in the navigation', function () {
    $page = ['slug' => 'dashboard', 'blocks' => [
        ['type' => 'metric_grid', 'items' => []],
    ]];

    expect(PageNavigation::isNavigable($page))->toBeTrue();
});

it('excludes a record-scoped detail page (record_detail keyed by a route param)', function () {
    $page = ['slug' => 'productos_detail', 'blocks' => [
        ['type' => 'breadcrumb', 'items' => []],
        ['type' => 'record_detail', 'object_id' => 'obj_x', 'record_id_expression' => '{{params.id}}'],
    ]];

    expect(PageNavigation::isNavigable($page))->toBeFalse();
});

it('detects a record_detail nested inside a container block', function () {
    $page = ['slug' => 'detalle', 'blocks' => [
        ['type' => 'tabs', 'blocks' => [
            ['type' => 'record_detail', 'record_id_expression' => '{{params.id}}'],
        ]],
    ]];

    expect(PageNavigation::isNavigable($page))->toBeFalse();
});

it('keeps a filterable list page that only reads a param in a data filter', function () {
    // A record_detail is the detail marker; a table filtered by {{params.status}}
    // is still a directly-addressable list and must stay in the menu.
    $page = ['slug' => 'productos', 'blocks' => [
        ['type' => 'filter_bar', 'fields' => []],
        ['type' => 'table', 'data_source' => ['filter' => ['field' => 'status', 'value' => '{{params.status}}']]],
    ]];

    expect(PageNavigation::isNavigable($page))->toBeTrue();
});

it('honours an explicit nav:false override', function () {
    $page = ['slug' => 'hidden_page', 'nav' => false, 'blocks' => [
        ['type' => 'table'],
    ]];

    expect(PageNavigation::isNavigable($page))->toBeFalse();
});

it('treats a record_detail bound to a fixed id (not a param) as navigable', function () {
    $page = ['slug' => 'singleton', 'blocks' => [
        ['type' => 'record_detail', 'record_id_expression' => 'rec_fixed_singleton'],
    ]];

    expect(PageNavigation::isNavigable($page))->toBeTrue();
});

// --- activeSlug: a child page lights its parent list in the menu ---

function warehousePages(): array
{
    return [
        ['slug' => 'categorias', 'blocks' => [
            ['type' => 'table', 'data_source' => ['object_id' => 'obj_cat']],
        ]],
        ['slug' => 'categorias_detail', 'blocks' => [
            ['type' => 'record_detail', 'object_id' => 'obj_cat', 'record_id_expression' => '{{params.id}}'],
        ]],
    ];
}

it('a list page activates itself', function () {
    $pages = warehousePages();

    expect(PageNavigation::activeSlug($pages[0], $pages))->toBe('categorias');
});

it('a detail page reports its parent list slug so the menu keeps the parent active', function () {
    $pages = warehousePages();

    expect(PageNavigation::activeSlug($pages[1], $pages))->toBe('categorias');
});

it('finds the parent list even when the detail block is nested in a container', function () {
    $pages = [
        ['slug' => 'productos', 'blocks' => [
            ['type' => 'kanban', 'data_source' => ['object_id' => 'obj_prod']],
        ]],
        ['slug' => 'producto_detalle', 'blocks' => [
            ['type' => 'tabs', 'blocks' => [
                ['type' => 'record_detail', 'object_id' => 'obj_prod', 'record_id_expression' => '{{params.id}}'],
            ]],
        ]],
    ];

    expect(PageNavigation::activeSlug($pages[1], $pages))->toBe('productos');
});

it('falls back to the detail page slug when no parent list exists', function () {
    $pages = [
        ['slug' => 'orphan_detail', 'blocks' => [
            ['type' => 'record_detail', 'object_id' => 'obj_gone', 'record_id_expression' => '{{params.id}}'],
        ]],
    ];

    expect(PageNavigation::activeSlug($pages[0], $pages))->toBe('orphan_detail');
});

it('does not treat a dashboard that merely aggregates the object as the parent list', function () {
    // The dashboard counts obj_cat in a metric, but only the table page LISTS it.
    $pages = [
        ['slug' => 'dashboard', 'blocks' => [
            ['type' => 'metric_grid', 'items' => [['query' => ['object_id' => 'obj_cat']]]],
        ]],
        ['slug' => 'categorias', 'blocks' => [
            ['type' => 'table', 'data_source' => ['object_id' => 'obj_cat']],
        ]],
        ['slug' => 'categorias_detail', 'blocks' => [
            ['type' => 'record_detail', 'object_id' => 'obj_cat', 'record_id_expression' => '{{params.id}}'],
        ]],
    ];

    expect(PageNavigation::activeSlug($pages[2], $pages))->toBe('categorias');
});
