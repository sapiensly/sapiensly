<?php

/**
 * Faker (fakerphp/faker) is a DEV-ONLY dependency, so calling `fake()` from
 * runtime code under app/ throws in production (composer install --no-dev) — a
 * 500, not a clean error. This guard keeps it out of app/ (DemoDataGenerator
 * once used it and broke generate_demo_data in prod). `Http::fake()` /
 * `Storage::fake()` are unrelated test doubles and allowed.
 */
it('does not call Faker fake() anywhere under app/', function () {
    $appDir = dirname(__DIR__, 3).'/app';
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $lines = preg_split('/\R/', (string) file_get_contents($file->getPathname()));
        foreach ($lines as $n => $line) {
            // A bare fake() helper call, not Http::fake()/Storage::fake().
            if (preg_match('/(?<![A-Za-z0-9_:>])fake\s*\(/', $line)) {
                $offenders[] = substr($file->getPathname(), strlen($appDir) + 1).':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
