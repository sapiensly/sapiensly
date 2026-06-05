<?php

/**
 * The platform (runtime) connection must be routable independently of the
 * pgsql (owner/migrations) connection, so production can send per-request
 * traffic through the Supabase pooler while migrations keep the direct
 * connection. Both read PLATFORM_DB_HOST/PORT, falling back to DB_HOST/PORT.
 */
function freshDatabaseConfig(): array
{
    return require base_path('config/database.php');
}

afterEach(function () {
    foreach (['DB_HOST', 'DB_PORT', 'PLATFORM_DB_HOST', 'PLATFORM_DB_PORT'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

test('PLATFORM_DB_HOST routes the platform connection away from the owner host', function () {
    foreach (['DB_HOST' => 'direct.supabase.co', 'PLATFORM_DB_HOST' => 'pooler.supabase.com'] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $config = freshDatabaseConfig();

    expect($config['connections']['platform']['host'])->toBe('pooler.supabase.com')
        ->and($config['connections']['pgsql']['host'])->toBe('direct.supabase.co');
});

test('the platform host falls back to DB_HOST when PLATFORM_DB_HOST is unset', function () {
    putenv('DB_HOST=shared.example.com');
    $_ENV['DB_HOST'] = 'shared.example.com';
    $_SERVER['DB_HOST'] = 'shared.example.com';

    $config = freshDatabaseConfig();

    expect($config['connections']['platform']['host'])->toBe('shared.example.com');
});
