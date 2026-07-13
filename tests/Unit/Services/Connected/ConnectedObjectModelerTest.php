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

    // The identity path is ALSO a field. It used to be excluded — being the row's
    // identity looked like a reason not to model it as data — and a live source
    // grouped by day returns one row per DATE and calls that date its id. Dropping
    // it left such an object with numbers and no dimension at all, so every chart
    // built on it had no axis and could only draw one bar.
    expect($byPath)->toHaveKey('id')
        // List-valued keys are still skipped: an array is not a column.
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

it('keeps the DATE a daily series calls its id — or the source has no dimension', function () {
    // The exact shape of a live logistics source: one row per day, and the day IS
    // the row's identity. Modelled with the date dropped, the object was five
    // numbers and nothing to plot them against — so the builder produced four
    // charts titled "OTD Diario", "Retrasados por Día", a line and an area, and
    // every one of them rendered a single bar. The board looked professional and
    // said nothing.
    $result = (new ConnectedObjectModeler)->model([
        ['fecha' => '2026-06-13', 'total_pedidos' => 3, 'pedidos_entregados' => 2, 'otd_pct' => 66.67],
        ['fecha' => '2026-06-14', 'total_pedidos' => 8, 'pedidos_entregados' => 5, 'otd_pct' => 62.50],
    ], idPath: 'fecha');

    $byPath = collect($result['field_map'])->pluck('field_id', 'external_path');
    $types = collect($result['fields'])->pluck('type', 'id');

    expect($result['id_path'])->toBe('fecha')
        // The identity is still the identity — AND it is a field a chart can use.
        ->and($byPath)->toHaveKey('fecha')
        // As a DATE, so a chart can bucket it by day/week/month. A string here
        // would still leave the series unbucketable.
        ->and($types[$byPath['fecha']])->toBe('date');

    // Which is the whole point: the object now HAS a dimension.
    $dimensions = collect($result['fields'])
        ->filter(fn (array $f) => in_array($f['type'], ['date', 'datetime', 'string'], true));

    expect($dimensions)->not->toBeEmpty();
});
