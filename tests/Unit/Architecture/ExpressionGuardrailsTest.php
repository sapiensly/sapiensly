<?php

use App\Services\Records\SafeExpressionEvaluator;

/**
 * Regression guards for the two failure modes that bit us:
 *  - constructor-default wiring drift (a required dependency added to a class
 *    while call sites kept a stale `= new X()` default), and
 *  - function-catalog drift (the evaluator, validator and builder prompt
 *    disagreeing on which functions exist).
 */

/* ----------------------- wiring drift (the 500) --------------------- */

it('does not wire core services via a constructor default', function () {
    $forbidden = '/=\s*new\s+(ExpressionResolver|SafeExpressionEvaluator|DerivedFieldsResolver|RecordQueryService|ScriptRunner|BlockDataResolver)\b/';

    $offenders = [];
    $appDir = dirname(__DIR__, 3).'/app';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match($forbidden, (string) file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getPathname();
        }
    }

    // These dependencies must be injected (the container autowires them) so that
    // changing a constructor signature can never leave a broken default behind.
    expect($offenders)->toBe([]);
});

/* --------------------- catalog drift (the 422) ---------------------- */

it('registers exactly the functions declared in the catalog', function () {
    $evaluator = new SafeExpressionEvaluator;
    $method = (new ReflectionClass($evaluator))->getMethod('functionMap');
    $method->setAccessible(true);

    $registered = array_keys($method->invoke($evaluator));
    sort($registered);

    $catalog = SafeExpressionEvaluator::FUNCTIONS;
    sort($catalog);

    expect($registered)->toBe($catalog);
});

it('can actually call every function in the catalog', function () {
    $evaluator = new SafeExpressionEvaluator;

    foreach (SafeExpressionEvaluator::FUNCTIONS as $fn) {
        try {
            $evaluator->evaluate($fn.'()', []);
        } catch (Throwable $e) {
            // Arity/argument errors are fine; "function does not exist" is not.
            expect($e->getMessage())->not->toContain('does not exist');
        }
    }
});
