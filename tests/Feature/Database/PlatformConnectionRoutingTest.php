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

function setDbEnv(string $key, string $value): void
{
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

afterEach(function () {
    foreach ([
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_SSLMODE', 'DB_PERSISTENT',
        'PLATFORM_DB_HOST', 'PLATFORM_DB_PORT', 'PLATFORM_DB_DATABASE', 'PLATFORM_DB_SSLMODE',
        'TENANT_DB_DATABASE', 'TENANT_DB_SSLMODE',
    ] as $key) {
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

test('DB_SSLMODE drives sslmode on every postgres connection, defaulting to prefer', function () {
    $config = freshDatabaseConfig();

    foreach (['pgsql', 'platform', 'tenant'] as $connection) {
        expect($config['connections'][$connection]['sslmode'])->toBe('prefer');
    }

    putenv('DB_SSLMODE=require');
    $_ENV['DB_SSLMODE'] = 'require';
    $_SERVER['DB_SSLMODE'] = 'require';

    $config = freshDatabaseConfig();

    foreach (['pgsql', 'platform', 'tenant'] as $connection) {
        expect($config['connections'][$connection]['sslmode'])->toBe('require');
    }
});

test('per-connection sslmode overrides DB_SSLMODE for the mixed PgBouncer topology', function () {
    // require everywhere, except platform which goes through a local PgBouncer
    // with no client TLS.
    setDbEnv('DB_SSLMODE', 'require');
    setDbEnv('PLATFORM_DB_SSLMODE', 'disable');

    $config = freshDatabaseConfig();

    expect($config['connections']['pgsql']['sslmode'])->toBe('require')
        ->and($config['connections']['tenant']['sslmode'])->toBe('require')
        ->and($config['connections']['platform']['sslmode'])->toBe('disable');
});

test('per-connection database overrides DB_DATABASE on the runtime connections', function () {
    setDbEnv('DB_DATABASE', 'owner_db');
    setDbEnv('PLATFORM_DB_DATABASE', 'platform_pool');
    setDbEnv('TENANT_DB_DATABASE', 'tenant_pool');

    $config = freshDatabaseConfig();

    expect($config['connections']['pgsql']['database'])->toBe('owner_db')
        ->and($config['connections']['platform']['database'])->toBe('platform_pool')
        ->and($config['connections']['tenant']['database'])->toBe('tenant_pool');
});

test('DB_PERSISTENT toggles persistent PDO on the runtime connections, defaulting off', function () {
    $config = freshDatabaseConfig();

    foreach (['platform', 'tenant'] as $connection) {
        expect($config['connections'][$connection]['options'][PDO::ATTR_PERSISTENT])->toBeFalse();
    }

    putenv('DB_PERSISTENT=true');
    $_ENV['DB_PERSISTENT'] = 'true';
    $_SERVER['DB_PERSISTENT'] = 'true';

    $config = freshDatabaseConfig();

    foreach (['platform', 'tenant'] as $connection) {
        expect($config['connections'][$connection]['options'][PDO::ATTR_PERSISTENT])->toBeTrue();
    }
});
