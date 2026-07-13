<?php

use App\Services\Analyst\MaturationCheck;
use App\Services\Analyst\RatioIdentity;
use App\Services\Express\SemanticProfile;

/**
 * The last rows are not bad news. They are not news yet.
 *
 * A live source reports an order the moment it is placed, but cannot mark it
 * delivered-on-time until the promised date arrives. So the tail of a delivery
 * series always reads as catastrophe — 0 delivered of 67, every day, on normal
 * volume — and a board built on it told the director the operation had collapsed
 * to zero and to go shout at a courier. The rate over the window that actually
 * resolved was 86%.
 *
 * Every other guard we own asks HOW a number was computed. This one asks whether
 * the data is RIPE, which no amount of arithmetic hygiene can answer.
 *
 * The discriminator is the whole idea: a genuine collapse RESOLVES orders badly,
 * so the late count climbs. An immature period resolves nothing at all — zero
 * delivered AND zero late. That is why the check can be sure instead of merely
 * suspicious, and why it must never be a rule about names or recency.
 */
function maturationObject(): array
{
    return [
        'id' => 'obj_mat00000', 'slug' => 'otd', 'name' => 'OTD',
        'fields' => [
            ['id' => 'f_fecha', 'slug' => 'fecha', 'name' => 'Fecha', 'type' => 'date'],
            ['id' => 'f_total', 'slug' => 'total_pedidos', 'name' => 'Total Pedidos', 'type' => 'number'],
            ['id' => 'f_ok', 'slug' => 'pedidos_entregados', 'name' => 'Pedidos Entregados', 'type' => 'number'],
            ['id' => 'f_late', 'slug' => 'pedidos_retrasados', 'name' => 'Pedidos Retrasados', 'type' => 'number'],
            ['id' => 'f_rate', 'slug' => 'otd_pct', 'name' => 'Otd Pct', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected', 'integration_id' => 'i',
            'field_map' => [
                ['field_id' => 'f_fecha', 'external_path' => 'fecha'],
                ['field_id' => 'f_total', 'external_path' => 'total'],
                ['field_id' => 'f_ok', 'external_path' => 'ok'],
                ['field_id' => 'f_late', 'external_path' => 'late'],
                ['field_id' => 'f_rate', 'external_path' => 'rate'],
            ],
            'operations' => ['list' => ['mcp_tool' => 'x', 'collection_path' => 'rows']],
        ],
    ];
}

/** @param  list<array{0:int,1:int,2:int}>  $days  [total, delivered, late] */
function maturationRows(array $days): array
{
    $rows = [];
    foreach ($days as $i => [$total, $ok, $late]) {
        $rows[] = [
            'fecha' => sprintf('2026-06-%02d', $i + 1),
            'total' => $total, 'ok' => $ok, 'late' => $late,
            'rate' => round(($ok - $late) / $total * 100, 2),
        ];
    }

    return $rows;
}

function maturationOf(array $rows): array
{
    $object = maturationObject();
    $identities = (new RatioIdentity(new SemanticProfile))->detect($object, $rows);

    return (new MaturationCheck)->detect($object, $rows, $identities);
}

/** Twelve normal days: ~95% of orders resolve, a few are late. */
function settledDays(): array
{
    return [
        [50, 47, 3], [48, 46, 2], [52, 50, 4], [49, 46, 2], [51, 48, 3], [50, 47, 1],
        [47, 45, 2], [53, 50, 3], [50, 48, 2], [48, 45, 4], [52, 49, 2], [50, 47, 3],
    ];
}

it('sees that the last days have not happened yet, and proves it', function () {
    // The Yuhu tail: volume is NORMAL, delivered decays to zero — and so does late,
    // because an order that has not been delivered cannot be late yet.
    $rows = maturationRows([...settledDays(), [50, 30, 0], [52, 12, 0], [49, 0, 0], [51, 0, 0]]);

    $found = maturationOf($rows);
    expect($found)->toHaveCount(1);
    $m = $found[0];

    expect($m['immature_periods'])->toBe(4)
        // Not a hunch about recency: the outcome share collapsed while volume held.
        ->and($m['baseline_resolved_pct'])->toBeGreaterThan(90.0)
        ->and($m['tail_resolved_pct'])->toBeLessThan(30.0)
        // The proof. A real collapse produces late orders; this produced none.
        ->and($m['conclusive'])->toBeTrue()
        // And the size of the lie the board was about to tell.
        ->and($m['mature_rate'])->toBeGreaterThan($m['full_window_rate'] + 10);
});

it('does not mistake a real collapse for a lag — the late count is the difference', function () {
    // Same shape, same volume, delivered falls just as hard. But the orders DID
    // resolve: they resolved late. That is a catastrophe and must be reported as
    // one, not quietly deleted from the analysis.
    $rows = maturationRows([...settledDays(), [50, 30, 28], [52, 12, 11], [49, 5, 5], [51, 4, 4]]);

    expect(maturationOf($rows))->toBeEmpty();
});

it('does not call a quiet day an unresolved one', function () {
    // Almost no orders placed. The outcome share is low because the denominator is
    // tiny, not because the period is young — deleting it would hide a real slump.
    $rows = maturationRows([...settledDays(), [2, 0, 0], [1, 0, 0], [2, 0, 0]]);

    expect(maturationOf($rows))->toBeEmpty();
});

it('hedges when the source gives it no way to be sure', function () {
    // shipped_pct = shipped / total, with no late column. Nothing distinguishes
    // "not shipped yet" from "never shipped", so the check reports the tail but
    // refuses to call it proven. Saying "probable" when you cannot prove it is the
    // whole difference between a guard and a guess.
    $object = maturationObject();
    $object['fields'] = array_values(array_filter($object['fields'], fn ($f) => $f['id'] !== 'f_late'));
    $rows = [];
    foreach ([[50, 47], [48, 46], [52, 50], [49, 46], [51, 48], [50, 47], [47, 45], [53, 50],
        [50, 48], [48, 45], [52, 49], [50, 47], [50, 10], [52, 4], [49, 0]] as $i => [$total, $ok]) {
        $rows[] = ['fecha' => sprintf('2026-06-%02d', $i + 1), 'total' => $total, 'ok' => $ok, 'rate' => round($ok / $total * 100, 2)];
    }

    $identities = (new RatioIdentity(new SemanticProfile))->detect($object, $rows);
    $found = (new MaturationCheck)->detect($object, $rows, $identities);

    expect($found)->toHaveCount(1)
        ->and($found[0]['immature_periods'])->toBe(3)
        ->and($found[0]['conclusive'])->toBeFalse();
});

it('hands back the window that actually resolved', function () {
    $rows = maturationRows([...settledDays(), [50, 30, 0], [52, 12, 0], [49, 0, 0], [51, 0, 0]]);

    $mature = (new MaturationCheck)->matureRows($rows, maturationOf($rows));

    // The twelve settled days, and not one of the four that have not happened.
    expect($mature)->toHaveCount(12)
        ->and(end($mature)['fecha'])->toBe('2026-06-12');
});
