<?php

use App\Services\Records\FieldPaths;

/**
 * ConnectedObjectReader flattens each external row to manifest slugs, so a
 * nested external_path ("totals.total_tickets") stops resolving against the flat
 * slug key ("totals_total_tickets") — the analyst then reads a full column as
 * empty. restoreExternalShape rebuilds the nesting FieldPaths::forObject promises
 * without dropping the slug keys, so both a path read and a slug read resolve.
 */
function nestedObject(): array
{
    return [
        'id' => 'obj_x', 'slug' => 'tickets', 'name' => 'Tickets',
        'fields' => [
            ['id' => 'f_key', 'slug' => 'key', 'type' => 'string'],
            ['id' => 'f_total', 'slug' => 'totals_total_tickets', 'type' => 'number'],
            ['id' => 'f_open', 'slug' => 'totals_backlog_open', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_key', 'external_path' => 'key'],
                ['field_id' => 'f_total', 'external_path' => 'totals.total_tickets'],
                ['field_id' => 'f_open', 'external_path' => 'totals.backlog_open'],
            ],
        ],
    ];
}

it('rebuilds a nested external_path from the flat slug so the path read resolves', function () {
    $rows = [
        ['key' => 'cobranza', 'totals_total_tickets' => 647, 'totals_backlog_open' => 12],
        ['key' => 'garantias', 'totals_total_tickets' => 0, 'totals_backlog_open' => 3],
    ];

    $out = FieldPaths::restoreExternalShape(nestedObject(), $rows);
    $paths = FieldPaths::forObject(nestedObject());

    // The path forObject hands out ("totals.total_tickets") now resolves…
    expect(data_get($out[0], $paths['f_total']))->toBe(647)
        ->and(data_get($out[0], $paths['f_open']))->toBe(12)
        // …including a legitimate zero (not conflated with "missing").
        ->and(data_get($out[1], $paths['f_total']))->toBe(0)
        // …and the flat slug key is KEPT, so a slug fallback still works.
        ->and($out[0]['totals_total_tickets'])->toBe(647)
        // …and a flat path (external_path == slug) is untouched.
        ->and($out[0]['key'])->toBe('cobranza');
});

it('leaves internal (field_map-less) rows untouched', function () {
    $object = [
        'id' => 'obj_i', 'slug' => 'notes',
        'fields' => [['id' => 'f_body', 'slug' => 'body', 'type' => 'string']],
        'source' => ['type' => 'internal'],
    ];
    $rows = [['body' => 'hi', 'id' => 'r1']];

    expect(FieldPaths::restoreExternalShape($object, $rows))->toBe($rows);
});

it('is a no-op when every external_path is already flat', function () {
    $object = [
        'id' => 'obj_f', 'slug' => 'fcr',
        'fields' => [['id' => 'f_pct', 'slug' => 'fcr_pct', 'type' => 'number']],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [['field_id' => 'f_pct', 'external_path' => 'fcr_pct']],
        ],
    ];
    $rows = [['fcr_pct' => 88.2]];

    expect(FieldPaths::restoreExternalShape($object, $rows))->toBe($rows);
});
