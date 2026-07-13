<?php

use App\Services\Analyst\GroundTruth;
use App\Services\Analyst\MaturationCheck;
use App\Services\Analyst\RatioIdentity;
use App\Services\Express\SemanticProfile;
use App\Support\Ai\FactGuard;

function groundTruth(): GroundTruth
{
    return new GroundTruth(new RatioIdentity(new SemanticProfile), new MaturationCheck);
}

/**
 * What the data can back, and what a model made up.
 *
 * The Yuhu board shipped an insight reading "solo 92 de 265 pedidos entregados a
 * tiempo (50.2%)". 265 was real. 50.2% was real. 92 was invented — and 92/265 is
 * 34.7%, so the sentence did not even agree with itself. It sat next to a chart
 * that WAS real, which is what makes an ungrounded figure worse than no card.
 *
 * The guard has to be generous or it is useless: reject a true sentence once and
 * the next person strips the guard rather than the lie. So ground truth is the
 * LATTICE the rows support — cells, totals, shares, averages, the weighted rate —
 * not the cells alone.
 */
function otdObject(): array
{
    return [
        'id' => 'obj_x', 'slug' => 'otd', 'name' => 'OTD',
        'fields' => [
            ['id' => 'f_fecha', 'slug' => 'fecha', 'name' => 'Fecha', 'type' => 'date'],
            ['id' => 'f_conv', 'slug' => 'convenio', 'name' => 'Convenio', 'type' => 'string'],
            ['id' => 'f_total', 'slug' => 'total_pedidos', 'name' => 'Total', 'type' => 'number'],
            ['id' => 'f_ok', 'slug' => 'pedidos_entregados', 'name' => 'Entregados', 'type' => 'number'],
            ['id' => 'f_late', 'slug' => 'pedidos_retrasados', 'name' => 'Retrasados', 'type' => 'number'],
            [
                'id' => 'f_rate', 'slug' => 'otd_pct', 'name' => 'Otd Pct', 'type' => 'number',
                'derived_rate' => [
                    'numerator_field_id' => 'f_ok',
                    'minus_field_id' => 'f_late',
                    'denominator_field_id' => 'f_total',
                    'verified_on_rows' => 4,
                ],
            ],
        ],
    ];
}

function otdRows(): array
{
    // 100 orders: 60 delivered, 10 late -> the true rate is (60-10)/100 = 50%.
    // Tresguerras holds 40 of the 100 = 40% of volume.
    return [
        ['fecha' => '2026-07-06', 'convenio' => 'Tresguerras', 'total_pedidos' => 25, 'pedidos_entregados' => 10, 'pedidos_retrasados' => 5, 'otd_pct' => 20],
        ['fecha' => '2026-07-07', 'convenio' => 'Tresguerras', 'total_pedidos' => 15, 'pedidos_entregados' => 5, 'pedidos_retrasados' => 5, 'otd_pct' => 0],
        ['fecha' => '2026-07-08', 'convenio' => 'Yuhuprais', 'total_pedidos' => 30, 'pedidos_entregados' => 25, 'pedidos_retrasados' => 0, 'otd_pct' => 83.33],
        ['fecha' => '2026-07-09', 'convenio' => 'Yuhuprais', 'total_pedidos' => 30, 'pedidos_entregados' => 20, 'pedidos_retrasados' => 0, 'otd_pct' => 66.67],
    ];
}

function grounds(string $prose): bool
{
    return FactGuard::onlyKnownNumbers($prose, groundTruth()->forObject(otdObject(), otdRows()));
}

it('catches the invented number that shipped to the director', function () {
    // The real card. Everything about it reads true.
    expect(grounds('Solo 92 de 100 pedidos entregados a tiempo (50%).'))->toBeFalse();

    // And the guard can NAME the lie, which is what makes the rejection useful:
    // "this says 92" beats "this is ungrounded".
    $truth = groundTruth()->forObject(otdObject(), otdRows());
    $invented = array_diff(
        FactGuard::numbersIn('Solo 92 de 100 pedidos entregados a tiempo (50%).'),
        FactGuard::numbersIn($truth),
    );
    expect(array_values($invented))->toBe(['92']);
});

it('backs the weighted rate, which is the number that IS true', function () {
    // (60 - 10) / 100. No cell holds 50; the identity does. If ground truth were
    // only the cells, the analyst's own honest figure would be called a lie.
    expect(grounds('El OTD real del periodo es 50%.'))->toBeTrue();
});

it('backs a concentration claim, whose percentage lives in no cell', function () {
    // Tresguerras: 40 of 100 orders. The 40% is derived, and a pareto insight is
    // the most valuable card on a board — rejecting it would gut the feature.
    expect(grounds('Tresguerras concentra 40% del volumen (40 de 100 pedidos).'))->toBeTrue();
});

it('backs totals, averages and dates as written', function () {
    expect(grounds('En 4 días se movieron 100 pedidos, 25 en promedio.'))->toBeTrue()
        // "el 6 de julio" must match the 2026-07-06 it was read from: a guard that
        // rejects a true sentence over a leading zero teaches people to route around it.
        ->and(grounds('El 6 de julio cayó a 20%.'))->toBeTrue();
});

it('refuses the figure that does not divide to the percentage it claims', function () {
    // The precise Yuhu failure: a count and a percentage that cannot both be true.
    // 30 of 100 is 30%, not 50% — and the guard catches it because 30-as-a-share
    // of the volume is not a number these rows produce.
    expect(grounds('Tresguerras: 23 pedidos sin entregar.'))->toBeFalse();
});
