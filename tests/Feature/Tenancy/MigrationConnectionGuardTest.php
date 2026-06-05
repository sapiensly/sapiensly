<?php

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

function fireCommand(string $command, array $argv): void
{
    event(new CommandStarting(
        $command,
        new ArgvInput(array_merge(['artisan', $command], $argv)),
        new BufferedOutput,
    ));
}

test('a migration command without --database is forced onto the owner connection', function () {
    config(['database.default' => 'platform']);

    fireCommand('migrate', []);

    expect(config('database.default'))->toBe('pgsql');
});

test('migrate:fresh and db:seed are also forced onto the owner connection', function (string $command) {
    config(['database.default' => 'platform']);

    fireCommand($command, []);

    expect(config('database.default'))->toBe('pgsql');
})->with(['migrate:fresh', 'db:seed', 'db:wipe']);

test('an explicit --database is respected', function () {
    config(['database.default' => 'platform']);

    fireCommand('migrate', ['--database=platform']);

    expect(config('database.default'))->toBe('platform');
});

test('non-migration commands are not redirected', function () {
    config(['database.default' => 'platform']);

    fireCommand('route:list', []);

    expect(config('database.default'))->toBe('platform');
});
