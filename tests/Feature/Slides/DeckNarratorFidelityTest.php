<?php

use App\Services\Slides\DeckNarrator;

/**
 * A Living Deck's whole promise is that its numbers are live. The refresh asks a
 * model to fix prose that the new data made stale — and the model hands back the
 * WHOLE slide, so it can rewrite a value as easily as a takeaway. The prompt said
 * "NEVER touch labels/series/values"; nothing made that true, and the editor
 * downstream validates a slide's structure, not its fidelity to the one it
 * replaces. A refreshed deck could quietly ship a figure the data never produced.
 */
function deckManifest(): array
{
    return [
        'title' => 'Q2',
        'slides' => [
            [
                'layout' => 'big_number',
                'value' => '412',
                'label' => 'Backlog abierto',
                'context' => 'Baja desde 380.',   // stale: it actually rose
            ],
            [
                'layout' => 'chart',
                'chart_type' => 'bar',
                'labels' => ['Envíos', 'Cobranza'],
                'series' => [['name' => 'Tickets', 'data' => [412, 286]]],
                'takeaway' => 'Cobranza lidera el volumen.',  // stale: Envíos does
            ],
            [
                'layout' => 'metrics',
                'items' => [
                    ['label' => 'FCR', 'value' => '74%', 'delta' => '+2 pts'],
                    ['label' => 'CSAT', 'value' => '88', 'delta' => '-1'],
                ],
            ],
        ],
    ];
}

function keptOps(array $operations): array
{
    $narrator = app(DeckNarrator::class);
    $method = new ReflectionMethod($narrator, 'faithfulOperations');

    return $method->invoke($narrator, $operations, deckManifest());
}

it('keeps a narrative op that only fixed the stale prose', function () {
    $slide = deckManifest()['slides'][0];
    $slide['context'] = 'Sube desde 380 — el pico del trimestre.';

    expect(keptOps([['op' => 'replace', 'index' => 0, 'slide' => $slide]]))->toHaveCount(1);
});

it('drops an op that rewrote a value it was never invited to touch', function () {
    // The takeaway is fixed — and the backlog is quietly restated as 520.
    $slide = deckManifest()['slides'][0];
    $slide['context'] = 'Sube desde 380.';
    $slide['value'] = '520';

    expect(keptOps([['op' => 'replace', 'index' => 0, 'slide' => $slide]]))->toBeEmpty();
});

it('drops an op that rewrote a chart series or its labels', function () {
    $tamperedSeries = deckManifest()['slides'][1];
    $tamperedSeries['takeaway'] = 'Envíos lidera el volumen.';
    $tamperedSeries['series'] = [['name' => 'Tickets', 'data' => [500, 286]]];

    $tamperedLabels = deckManifest()['slides'][1];
    $tamperedLabels['takeaway'] = 'Envíos lidera el volumen.';
    $tamperedLabels['labels'] = ['Envíos', 'Garantías'];

    expect(keptOps([['op' => 'replace', 'index' => 1, 'slide' => $tamperedSeries]]))->toBeEmpty()
        ->and(keptOps([['op' => 'replace', 'index' => 1, 'slide' => $tamperedLabels]]))->toBeEmpty();
});

it('lets a metric fix its delta, but not its value', function () {
    // A metrics slide carries prose INSIDE its items, so a legitimate delta fix
    // must survive the check — and a value change inside the same array must not.
    $ok = deckManifest()['slides'][2];
    $ok['items'][0]['delta'] = '+5 pts';

    $tampered = deckManifest()['slides'][2];
    $tampered['items'][0]['delta'] = '+5 pts';
    $tampered['items'][0]['value'] = '91%';

    $dropped = deckManifest()['slides'][2];
    array_pop($dropped['items']);   // removing a metric is not a rewrite

    expect(keptOps([['op' => 'replace', 'index' => 2, 'slide' => $ok]]))->toHaveCount(1)
        ->and(keptOps([['op' => 'replace', 'index' => 2, 'slide' => $tampered]]))->toBeEmpty()
        ->and(keptOps([['op' => 'replace', 'index' => 2, 'slide' => $dropped]]))->toBeEmpty();
});

it('drops an op aimed at a slide that does not exist', function () {
    $slide = deckManifest()['slides'][0];

    expect(keptOps([['op' => 'replace', 'index' => 9, 'slide' => $slide]]))->toBeEmpty()
        ->and(keptOps([['op' => 'insert', 'index' => 0, 'slide' => $slide]]))->toBeEmpty();
});
