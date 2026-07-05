<?php

use App\Services\Connected\ConnectedObjectModeler;

beforeEach(function () {
    $this->modeler = new ConnectedObjectModeler;
});

it('infers types, slugs and the id path from real rows', function () {
    $result = $this->modeler->model([
        [
            'id' => 'T-1', 'Número de Folio' => '00123', 'status' => 'abierto',
            'is_fcr' => true, 'handle_minutes' => 42,
            'created_at' => '2026-07-01T10:00:00Z', 'due_on' => '2026-07-09',
            'metrics' => ['csat' => 4.5],
            'tags' => ['a', 'b'],
        ],
        [
            'id' => 'T-2', 'Número de Folio' => '00124', 'status' => 'cerrado',
            'is_fcr' => false, 'handle_minutes' => 7,
            'created_at' => '2026-07-02 09:30:00', 'due_on' => '2026-07-10',
            'metrics' => ['csat' => 3.0],
            'tags' => [],
        ],
    ]);

    expect($result['id_path'])->toBe('id');

    $byPath = collect($result['field_map'])->pluck('field_id', 'external_path');
    $types = collect($result['fields'])->pluck('type', 'id');
    $slugs = collect($result['fields'])->pluck('slug', 'id');

    // id excluded from fields; list-valued keys (tags) skipped.
    expect($byPath)->not->toHaveKey('id')
        ->and($byPath)->not->toHaveKey('tags');

    expect($types[$byPath['status']])->toBe('string')
        ->and($types[$byPath['is_fcr']])->toBe('boolean')
        ->and($types[$byPath['handle_minutes']])->toBe('number')
        ->and($types[$byPath['created_at']])->toBe('datetime')
        ->and($types[$byPath['due_on']])->toBe('date')
        // Nested one-level scalars become dot-path fields.
        ->and($types[$byPath['metrics.csat']])->toBe('number')
        // Numeric STRINGS stay strings (folio keeps its leading zeros).
        ->and($types[$byPath['Número de Folio']])->toBe('string')
        // Accented key transliterates to a clean slug.
        ->and($slugs[$byPath['Número de Folio']])->toBe('numero_de_folio')
        ->and($slugs[$byPath['metrics.csat']])->toBe('metrics_csat');
});

it('falls back to an *_id key when no plain id exists, honoring an explicit override', function () {
    $rows = [['ticket_id' => 'T-9', 'status' => 'open', 'external' => ['ref' => 'X1']]];

    expect($this->modeler->model($rows)['id_path'])->toBe('ticket_id');
    expect($this->modeler->model($rows, 'external.ref')['id_path'])->toBe('external.ref');
});

it('treats mixed or null-only values as strings and dedupes colliding slugs', function () {
    $result = $this->modeler->model([
        ['id' => 1, 'estado' => 'ok', 'Estado' => 'dup', 'empty' => null, 'mixed' => 5],
        ['id' => 2, 'estado' => 'ok', 'Estado' => 'dup', 'empty' => null, 'mixed' => 'five'],
    ]);

    $slugs = collect($result['fields'])->pluck('slug')->all();
    $types = collect($result['fields'])->pluck('type', 'slug');

    expect($slugs)->toContain('estado')
        ->and($slugs)->toContain('estado_2')
        ->and($types['empty'])->toBe('string')
        ->and($types['mixed'])->toBe('string');
});
