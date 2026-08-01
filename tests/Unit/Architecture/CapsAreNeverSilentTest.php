<?php

/**
 * A cap that truncates in silence.
 *
 * Four times now the same shape has shipped here: the model describes more than
 * a limit allows, the scaffolder keeps what fits, and the response says the app
 * was created. Fields went first — an add_object asking for 21 saved 12 and
 * reported success. Then whole objects, with every field on them. Then
 * relationships, which leave both objects standing so nothing looks missing.
 * Then the choices on a select, so a lifecycle's last state was unreachable
 * for ever.
 *
 * Each was found by someone noticing, months apart, and fixed one at a time.
 * This is the rule instead: wherever the scaffolder cuts a spec down to a
 * `MAX_*` bound, the method doing the cutting must also say what it left out.
 *
 * The check is deliberately shallow — it asks whether a coercion is written in
 * the same method, not whether the wording is any good. A shallow rule that
 * catches the next silent cap is worth more than a deep one nobody adds to.
 */
it('never trims a spec to a cap without saying what it dropped', function () {
    $path = dirname(__DIR__, 3).'/app/Services/Manifest/AppScaffolder.php';
    $source = file_get_contents($path) ?: '';

    // Split the class into methods, keeping each one's name and body together.
    // Brace counting rather than a parser: the file is one class of ordinary
    // methods, and a test that needs a dependency to read a file will rot.
    $methods = [];
    $offset = 0;
    while (preg_match('/function\s+([a-zA-Z]+)\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $name = $m[1][0];
        $start = (int) $m[0][1];
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            break;
        }

        $depth = 0;
        $end = $brace;
        for ($i = $brace, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        $methods[] = ['name' => $name, 'body' => substr($source, $brace, $end - $brace + 1)];
        $offset = $end + 1;
    }

    expect($methods)->not->toBeEmpty('the source could not be read as methods');

    $silent = [];
    foreach ($methods as $method) {
        // Cutting a list down to a cap, however it is spelled.
        $cuts = preg_match('/(array_slice|array_splice)\s*\([^;]*self::MAX_[A-Z_]+/', $method['body']) === 1
            || preg_match('/self::MAX_[A-Z_]+\s*\)?\s*;?\s*$/m', $method['body']) === 1
                && preg_match('/>\s*self::MAX_[A-Z_]+/', $method['body']) === 1;

        if (! $cuts) {
            continue;
        }
        // …must be accompanied by telling the caller, in the same method.
        if (! str_contains($method['body'], '$coercions[]')) {
            $silent[] = $method['name'];
        }
    }

    expect($silent)->toBeEmpty(
        'these methods trim a spec to a MAX_* cap without recording a coercion: '.implode(', ', $silent)
        .'. A truncated spec that reports success is how a field, an object, a relationship and a '
        .'select option have each gone missing here. Add a $coercions[] note naming what was left out.',
    );
});
