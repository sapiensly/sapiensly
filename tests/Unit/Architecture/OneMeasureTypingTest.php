<?php

use App\Services\Express\SemanticProfile;

/**
 * A column has ONE measure type. Two callers typing it differently is worse than
 * either being wrong: one path refuses to sum a rate while the other sums it, and
 * the same board then carries a number that cannot exist next to one that can.
 *
 * The value-based fallback in measureTypeOf() only fires when it is given values,
 * and for a long time nobody gave it any — so a rate whose slug missed the
 * regexes was silently additive. measureTypeIn() is the call that hands them over,
 * and the analytic layer must go through it.
 */
it('the analytic layer never types a column blind', function () {
    $blind = [];

    $root = dirname(__DIR__, 3);

    foreach (['app/Services/Analyst', 'app/Services/Express'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));
        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname()) ?: '';
            // The definition itself lives in SemanticProfile; everyone else must
            // either pass values or go through measureTypeIn().
            if (str_ends_with($file->getPathname(), 'SemanticProfile.php')) {
                continue;
            }
            // A call with no second argument is a blind read of the slug alone.
            if (preg_match('/measureTypeOf\(\s*\$[a-zA-Z]+\s*\)/', $source) === 1) {
                $blind[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }

    expect($blind)->toBeEmpty();
});

it('reads a rate off its name when the slug says nothing', function () {
    // The column that started it: a real rate wearing a meaningless slug. Typed
    // off the slug alone it was ADDITIVE — and additive means summable.
    $semantics = new SemanticProfile;

    expect($semantics->measureTypeOf(['slug' => 'col_7', 'name' => 'Tasa de reapertura']))
        ->toBe(SemanticProfile::MEASURE_RATIO)
        // …and a genuine count is still a count.
        ->and($semantics->measureTypeOf(['slug' => 'col_8', 'name' => 'Total de tickets']))
        ->toBe(SemanticProfile::MEASURE_ADDITIVE)
        // A nameless column is judged by the values it actually holds: all inside
        // 0-100 with decimals reads as a percentage, not a count.
        ->and($semantics->measureTypeOf(['slug' => 'col_9', 'name' => 'C9'], [3.2, 4.1, 2.8]))
        ->toBe(SemanticProfile::MEASURE_RATIO);
});
