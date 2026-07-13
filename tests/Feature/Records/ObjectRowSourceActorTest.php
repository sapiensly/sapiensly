<?php

use App\Models\App;
use App\Models\User;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\ObjectRowSource;

/**
 * The actor READS.
 *
 * A connected source behind per-user OAuth authenticates as the viewer, so a read
 * that forgets to hand the actor down comes back empty — and ObjectRowSource
 * swallows the failure, which turns "these credentials were never passed" into
 * "this app has no data". Found auditing a real board: the analyst reported zero
 * sources on a dashboard whose source returned 31 rows the moment it was asked
 * with an actor. The actor was being used for the cache key and nothing else.
 */
it('hands the actor down to the read, not just to the cache key', function () {
    config(['cache.default' => 'array']);

    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'objects' => [[
            'id' => 'obj_actor00000',
            'slug' => 'otd',
            'name' => 'OTD',
            'fields' => [['id' => 'fld_actor00000', 'slug' => 'otd_pct', 'name' => 'Otd Pct', 'type' => 'number']],
            'source' => ['type' => 'connected', 'integration_id' => 'integ_actor000'],
        ]],
    ];

    $seen = ['context' => null];
    $blockData = Mockery::mock(BlockDataResolver::class);
    $blockData->shouldReceive('queryObject')
        ->andReturnUsing(function ($app, $query, $manifest, $context = []) use (&$seen) {
            $seen['context'] = $context;

            return [['id' => '2026-06-13', 'data' => ['otd_pct' => 66.67]]];
        });

    $rows = (new ObjectRowSource($blockData))->sample($app, $manifest['objects'][0], $manifest, $user, 500);

    expect($rows)->toHaveCount(1)
        // Without this the connected read authenticates as nobody.
        ->and($seen['context']['__actor'] ?? null)->toBe($user);
});
