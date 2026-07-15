<?php

use App\Services\Manifest\AppScaffolder;
use App\Support\Branding\ColorPalette;

/**
 * The hero floats the headline KPI as a live figure. When that KPI is a RATE
 * (sum(numerator) ÷ ratio_denominator), the hero must carry the denominator too
 * — dropping it summed the numerator alone and printed it as a percentage
 * ("1,896,700% OTD"). This locks the hero stat to the same ratio the KPI card
 * already resolves correctly.
 */
it('floats a rate KPI into the hero WITH its ratio_denominator, not a bare sum', function () {
    $object = [
        'id' => 'obj_otd00000000',
        'slug' => 'otd',
        'name' => 'OTD',
        'fields' => [
            ['id' => 'fld_otddate000', 'slug' => 'bucket_start', 'name' => 'Semana', 'type' => 'date'],
            ['id' => 'fld_otdatiem00', 'slug' => 'a_tiempo', 'name' => 'A Tiempo', 'type' => 'number'],
            ['id' => 'fld_otdtotal00', 'slug' => 'entregados', 'name' => 'Entregados', 'type' => 'number'],
        ],
    ];

    $spec = [
        'title' => 'OTD',
        'date_field_id' => 'fld_otddate000',
        'include_hero' => true,
        'kpis' => [
            // A rate: sum(a_tiempo) / sum(entregados), shown as a percentage.
            [
                'label' => 'OTD%',
                'field_id' => 'fld_otdatiem00',
                'aggregation' => 'sum',
                'format' => 'percentage',
                'ratio_denominator' => ['aggregation' => 'sum', 'field_id' => 'fld_otdtotal00'],
            ],
        ],
        'charts' => [
            ['label' => 'Evolución', 'chart_type' => 'line', 'x_field_id' => 'fld_otddate000', 'y_field_id' => 'fld_otdatiem00', 'aggregation' => 'sum'],
        ],
    ];

    $built = app(AppScaffolder::class)->buildDashboardFromSpec(
        $spec, $object, [], ColorPalette::fromAccent('#0096ff'), 'es',
    );
    expect($built['ok'] ?? false)->toBeTrue();

    $hero = collect($built['page']['blocks'])->firstWhere('type', 'hero');
    expect($hero)->not->toBeNull()
        ->and($hero['stat'] ?? null)->not->toBeNull()
        // The headline picked the rate KPI (format percentage)…
        ->and($hero['stat']['format'])->toBe('percentage')
        // …and MUST carry the denominator, so it recomputes sum/sum live.
        ->and($hero['stat']['ratio_denominator'] ?? null)->not->toBeNull()
        ->and($hero['stat']['ratio_denominator']['field_id'])->toBe('fld_otdtotal00');
});
