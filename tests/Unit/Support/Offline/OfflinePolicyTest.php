<?php

use App\Support\Offline\OfflinePolicy;

/**
 * What an app is allowed to leave on a device.
 *
 * A cached page is tenant rows on a phone's disk in the clear. For a work order
 * that is the right trade; for a payroll run it is not, and the person who
 * knows which is which is the one who built the app.
 */
function op_manifest(array $offline = [], array $objects = []): array
{
    return [
        'objects' => $objects ?: [
            ['id' => 'obj_ordenes', 'slug' => 'ordenes', 'name' => 'Órdenes', 'fields' => []],
            ['id' => 'obj_nominas', 'slug' => 'nominas', 'name' => 'Nóminas', 'fields' => []],
        ],
        'settings' => $offline === [] ? [] : ['offline' => $offline],
    ];
}

it('lets an app that says nothing work offline', function () {
    // Offline shipped on for every app. An app that silently stopped working
    // in the basement it was built for is a worse failure than the one this
    // guards against, so the opt-out has to be said out loud.
    $policy = OfflinePolicy::for(op_manifest());

    expect($policy->enabled)->toBeTrue()
        ->and($policy->mayCachePage(['blocks' => [['object_id' => 'obj_nominas']]]))->toBeTrue();
});

it('refuses everything for an app that turned it off', function () {
    $policy = OfflinePolicy::for(op_manifest(['enabled' => false]));

    expect($policy->enabled)->toBeFalse()
        ->and($policy->mayCachePage(['blocks' => []]))->toBeFalse();
});

it('keeps an excluded object off the device without turning the app off', function () {
    $policy = OfflinePolicy::for(op_manifest(['exclude_objects' => ['nominas']]));

    expect($policy->enabled)->toBeTrue()
        ->and($policy->excludedObjectIds)->toBe(['obj_nominas']);
});

it('refuses a page because of what it READS, not what it is called', function () {
    // The author marks the DATA sensitive once. A page added next month that
    // happens to chart it is covered without anyone remembering to say so.
    $policy = OfflinePolicy::for(op_manifest(['exclude_objects' => ['nominas']]));

    expect($policy->mayCachePage(['slug' => 'ordenes', 'blocks' => [
        ['type' => 'table', 'data_source' => ['object_id' => 'obj_ordenes']],
    ]]))->toBeTrue();

    expect($policy->mayCachePage(['slug' => 'resumen', 'blocks' => [
        ['type' => 'chart', 'data_source' => ['object_id' => 'obj_nominas']],
    ]]))->toBeFalse();
});

it('finds the object however deeply a page buries it', function () {
    // A chart carries one per series and a tabs block hides them a level down.
    // The list of places an object_id can appear is exactly the list that goes
    // stale, so nothing enumerates it.
    $policy = OfflinePolicy::for(op_manifest(['exclude_objects' => ['nominas']]));

    $buried = ['blocks' => [[
        'type' => 'tabs',
        'tabs' => [
            ['label' => 'Uno', 'blocks' => [['type' => 'table', 'data_source' => ['object_id' => 'obj_ordenes']]]],
            ['label' => 'Dos', 'blocks' => [[
                'type' => 'container',
                'blocks' => [['type' => 'chart', 'series' => [['object_id' => 'obj_nominas']]]],
            ]]],
        ],
    ]]];

    expect($policy->mayCachePage($buried))->toBeFalse();
});

it('ignores an exclusion naming an object that does not exist', function () {
    // Resolved through the manifest's own objects, so a typo excludes nothing
    // rather than silently matching a slug the app never had.
    $policy = OfflinePolicy::for(op_manifest(['exclude_objects' => ['no_existe']]));

    expect($policy->excludedObjectIds)->toBe([])
        ->and($policy->mayCachePage(['blocks' => []]))->toBeTrue();
});

it('tells the client exactly what it needs to make the same call', function () {
    // The server's no-store is what stops a page being cached. This is for the
    // two things only the client can decide: whether to hold a write, and
    // whether to hold the photo attached to one.
    $policy = OfflinePolicy::for(op_manifest(['exclude_objects' => ['nominas']]));

    expect($policy->toClient())->toBe([
        'enabled' => true,
        'excluded_object_ids' => ['obj_nominas'],
    ]);
});
