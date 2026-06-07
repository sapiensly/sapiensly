<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'platform'),

    /*
    |--------------------------------------------------------------------------
    | Tenant connection persistence
    |--------------------------------------------------------------------------
    |
    | Mirrors the `tenant` connection's PDO::ATTR_PERSISTENT option (both read
    | DB_PERSISTENT). Exposed flat so request-scoped RLS hygiene can cheaply
    | decide whether it must reset the tenant scope on unauthenticated requests:
    | a persistent connection survives across requests on a worker, so a guest
    | request would otherwise inherit the previous request's tenant scope.
    |
    */

    'tenant_persistent' => env('DB_PERSISTENT', false),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         | Owner connection — migrations & DDL only. Runs as `postgres`, owns
         | both schemas, and bypasses RLS. Never used for application runtime
         | queries. `search_path` spans both schemas so migrations can create
         | tables in either without per-statement qualification surprises.
         */
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'platform,tenant,public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
         | Control-plane runtime — role `platform_app`. Reads/writes the
         | `platform` schema only; structurally cannot see `tenant`. This is the
         | application default connection, so any tenant model that forgets to
         | opt into the `tenant` connection fails loudly (relation not found)
         | instead of silently bypassing isolation.
         */
        'platform' => [
            'driver' => 'pgsql',
            'url' => env('PLATFORM_DB_URL'),
            // Own host/port (falling back to DB_*) so the runtime can target the
            // Supabase pooler while migrations (the `pgsql` owner connection)
            // keep the direct connection. See .env.example.
            'host' => env('PLATFORM_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('PLATFORM_DB_PORT', env('DB_PORT', '5432')),
            // Own database/sslmode (falling back to DB_*) so this connection can
            // go through a local PgBouncer (loopback, sslmode=disable) or a
            // pool-mode-specific virtual db name, independent of the direct
            // owner/tenant connections.
            'database' => env('PLATFORM_DB_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('PLATFORM_DB_USERNAME', 'platform_app'),
            'password' => env('PLATFORM_DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // `public` trails so shared extension types/operators resolve; no app
            // tables live there.
            'search_path' => 'platform,public',
            'sslmode' => env('PLATFORM_DB_SSLMODE', env('DB_SSLMODE', 'prefer')),
            // Persistent PDO connections amortize the per-request connect cost
            // (TCP+TLS+auth round-trips) across a PHP-FPM worker's lifetime —
            // the fix for high reconnect latency to a managed DB. Pair with the
            // Supabase TRANSACTION pooler (it multiplexes, so a held client
            // connection is cheap). Off by default; see DB_PERSISTENT.
            'options' => [PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false)],
        ],

        /*
         | Tenant-data runtime — role `tenant_app`. Reads/writes the `tenant`
         | schema only and is subject to RLS. The RLS GUCs (app.organization_id /
         | app.user_id) are set per request/job by TenantContext. Under Supabase
         | transaction pooling this connection should target the SESSION pooler
         | (TENANT_DB_HOST/PORT) so `SET app.*` survives the request; otherwise
         | TenantContext falls back to set_config(..., true) inside a transaction.
         */
        'tenant' => [
            'driver' => 'pgsql',
            'url' => env('TENANT_DB_URL'),
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '5432')),
            // Own database/sslmode (falling back to DB_*); lets tenant keep a
            // direct TLS session connection while platform goes via a local
            // PgBouncer, or point at a session-mode virtual db name.
            'database' => env('TENANT_DB_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('TENANT_DB_USERNAME', 'tenant_app'),
            'password' => env('TENANT_DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // `public` trails so the pgvector `<=>` operators / `vector` type
            // (created in public) resolve for RAG similarity queries.
            'search_path' => 'tenant,public',
            'sslmode' => env('TENANT_DB_SSLMODE', env('DB_SSLMODE', 'prefer')),
            // See the platform connection. On the SESSION pooler each persistent
            // connection holds a backend for the worker's life, so size FPM
            // workers against the pooler's capacity. Off by default.
            'options' => [PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false)],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            // `tls` enables an encrypted connection (required by managed
            // providers like Upstash); the connector prepends `tls://` to the
            // host. Leave as `tcp` for a plain local/self-hosted server.
            'scheme' => env('REDIS_SCHEME', 'tcp'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'scheme' => env('REDIS_SCHEME', 'tcp'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            // Defaults to a separate logical DB, but providers that expose only
            // DB 0 (e.g. Upstash) need REDIS_CACHE_DB=0 — keys stay isolated by
            // the `options.prefix` above, so sharing DB 0 is safe.
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
