<?php

use App\Support\Draft\DraftDiff;

it('labels an empty target as safe to fill and a written one as a conflict', function () {
    $diff = DraftDiff::between(
        ['descriptor' => 'What we wrote ourselves.', 'industry' => null, 'geographies' => []],
        ['descriptor' => 'What the website says.', 'industry' => 'logistics', 'geographies' => ['Mexico']],
    );

    expect($diff->additions())->toBe(['industry' => 'logistics', 'geographies' => ['Mexico']])
        ->and($diff->conflicts())->toBe(['descriptor' => 'What the website says.'])
        ->and($diff->hasConflicts())->toBeTrue();
});

it('treats an unchanged value as nothing to do', function () {
    $diff = DraftDiff::between(
        ['accent_color' => '#0F766E', 'font' => 'serif'],
        ['accent_color' => '#0f766e', 'font' => 'serif'],
    );

    expect($diff->additions())->toBe([])
        ->and($diff->conflicts())->toBe([])
        ->and($diff->isEmpty())->toBeTrue()
        ->and($diff->toArray())->toHaveCount(2)
        ->and($diff->toArray()[0]['status'])->toBe(DraftDiff::SAME);
});

/**
 * A draft that read no logo must never be read as "clear the logo": silence is
 * not an opinion, and this is the difference between a helpful draft and one
 * that wipes a brand.
 */
it('says nothing about fields the draft had nothing to say about', function () {
    $diff = DraftDiff::between(
        ['logo_url' => 'https://acme.example/logo.png', 'accent_color' => '#0f766e'],
        ['accent_color' => '#0f766e', 'logo_url' => null, 'icon_url' => '   '],
    );

    expect($diff->toArray())->toHaveCount(1)
        ->and($diff->toArray()[0]['field'])->toBe('accent_color');
});

it('keeps what the human wrote unless the conflict is named explicitly', function () {
    $current = ['descriptor' => 'Ours.', 'industry' => null, 'audience' => 'Ours too.'];
    $diff = DraftDiff::between($current, [
        'descriptor' => 'Theirs.',
        'industry' => 'logistics',
        'audience' => 'Theirs too.',
    ]);

    // Default: additions land, every conflict is kept as the human wrote it.
    expect($diff->applyTo($current))->toBe([
        'descriptor' => 'Ours.',
        'industry' => 'logistics',
        'audience' => 'Ours too.',
    ]);

    // Only the conflict the user accepted is replaced.
    expect($diff->applyTo($current, ['audience']))->toBe([
        'descriptor' => 'Ours.',
        'industry' => 'logistics',
        'audience' => 'Theirs too.',
    ]);
});

it('reports each field with both sides so the user can compare before deciding', function () {
    $entry = DraftDiff::between(['descriptor' => 'Ours.'], ['descriptor' => 'Theirs.'])->toArray()[0];

    expect($entry)->toBe([
        'field' => 'descriptor',
        'status' => DraftDiff::CONFLICT,
        'current' => 'Ours.',
        'proposed' => 'Theirs.',
    ]);
});
