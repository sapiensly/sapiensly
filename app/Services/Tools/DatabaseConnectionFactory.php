<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\DB;

/**
 * Builds (and tears down) a temporary, named Laravel DB connection from a
 * DSN-shaped config: {driver, host, port, database, username, password}.
 *
 * Shared by the database tool executor and the database connection's
 * test-connection, so both reach an external database the same way.
 */
class DatabaseConnectionFactory
{
    /**
     * Register a throwaway connection and return its name.
     *
     * @param  array<string, mixed>  $config
     */
    public function open(array $config): string
    {
        $name = 'tool_db_'.bin2hex(random_bytes(8));

        $connectionConfig = [
            'driver' => $config['driver'],
            'database' => $config['database'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ];

        if (($config['driver'] ?? null) !== 'sqlite') {
            $connectionConfig['host'] = $config['host'];
            $connectionConfig['port'] = $config['port'] ?? $this->defaultPort($config['driver']);
            $connectionConfig['username'] = $config['username'];
            $connectionConfig['password'] = $config['password'] ?? '';
        }

        if (($config['driver'] ?? null) === 'pgsql') {
            $connectionConfig['charset'] = 'utf8';
            unset($connectionConfig['collation']);
        }

        config(["database.connections.{$name}" => $connectionConfig]);

        return $name;
    }

    public function close(string $name): void
    {
        DB::purge($name);
        config(["database.connections.{$name}" => null]);
    }

    public function defaultPort(string $driver): int
    {
        return match ($driver) {
            'pgsql' => 5432,
            'mysql' => 3306,
            'sqlsrv' => 1433,
            default => 3306,
        };
    }
}
