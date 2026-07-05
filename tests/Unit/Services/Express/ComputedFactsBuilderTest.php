<?php

use App\Services\Express\ComputedFactsBuilder;

it('computes real aggregates the insight gate can narrate', function () {
    $object = [
        'name' => 'Tickets',
        'slug' => 'tickets',
        'fields' => [
            ['id' => 'f_cat', 'slug' => 'categoria', 'name' => 'Categoría', 'type' => 'string'],
            ['id' => 'f_min', 'slug' => 'minutos', 'name' => 'Minutos', 'type' => 'number'],
            ['id' => 'f_sla', 'slug' => 'sla', 'name' => 'SLA Incumplido', 'type' => 'boolean'],
            ['id' => 'f_dt', 'slug' => 'creado', 'name' => 'Creado', 'type' => 'datetime'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'f_cat', 'external_path' => 'category'],
            ['field_id' => 'f_min', 'external_path' => 'metrics.minutes'],
            ['field_id' => 'f_sla', 'external_path' => 'sla_breached'],
            ['field_id' => 'f_dt', 'external_path' => 'created_at'],
        ]],
    ];

    $recent = now()->utc()->subDays(2)->toIso8601String();
    $older = now()->utc()->subDays(10)->toIso8601String();
    $rows = [
        ['category' => 'Envíos', 'metrics' => ['minutes' => 30], 'sla_breached' => true, 'created_at' => $recent],
        ['category' => 'Envíos', 'metrics' => ['minutes' => 60], 'sla_breached' => false, 'created_at' => $recent],
        ['category' => 'Pagos', 'metrics' => ['minutes' => 90], 'sla_breached' => false, 'created_at' => $older],
        ['category' => 'Envíos', 'metrics' => ['minutes' => 20], 'sla_breached' => false, 'created_at' => $older],
    ];

    $facts = (new ComputedFactsBuilder)->build($object, $rows);

    expect($facts['row_count'])->toBe(4)
        ->and($facts['top_values']['Categoría']['top'])->toBe('Envíos')
        ->and($facts['top_values']['Categoría']['share_pct'])->toBe(75.0)
        ->and($facts['numeric']['Minutos']['sum'])->toBe(200.0)
        ->and($facts['numeric']['Minutos']['avg'])->toBe(50.0)
        ->and($facts['rates']['SLA Incumplido']['rate_pct'])->toBe(25.0)
        ->and($facts['trend']['Creado']['last_7d'])->toBe(2)
        ->and($facts['trend']['Creado']['previous_7d'])->toBe(2);
});

it('skips empty columns and high-cardinality strings', function () {
    $object = [
        'name' => 'X', 'slug' => 'x',
        'fields' => [
            ['id' => 'f_a', 'slug' => 'vacio', 'name' => 'Vacío', 'type' => 'string'],
            ['id' => 'f_b', 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
        ],
        'source' => ['field_map' => [
            ['field_id' => 'f_a', 'external_path' => 'empty'],
            ['field_id' => 'f_b', 'external_path' => 'folio'],
        ]],
    ];
    $rows = array_map(fn (int $i) => ['empty' => null, 'folio' => 'F-'.$i], range(1, 30));

    $facts = (new ComputedFactsBuilder)->build($object, $rows);

    expect($facts)->not->toHaveKey('top_values')
        ->and($facts['row_count'])->toBe(30);
});
