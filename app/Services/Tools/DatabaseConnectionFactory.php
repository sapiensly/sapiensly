<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\DB;

/**
 * Builds (and tears down) a temporary, named Laravel DB connection from a
 * DSN-shaped config: {driver, host, port, database, username, password}, plus
 * an optional `ssh` block that routes the connection through an SSH tunnel.
 *
 * Shared by the database tool executor and the database connection's
 * test-connection, so both reach an external database the same way.
 */
class DatabaseConnectionFactory
{
    /**
     * Live SSH tunnels keyed by the connection name that owns them, so close()
     * tears the tunnel down with the connection.
     *
     * @var array<string, SshTunnelHandle>
     */
    private array $tunnels = [];

    public function __construct(private readonly SshTunnel $sshTunnel) {}

    /**
     * Register a throwaway connection and return its name. When the config
     * carries an `ssh` block, an SSH tunnel is opened first and the connection
     * is pointed at its local end.
     *
     * @param  array<string, mixed>  $config
     */
    public function open(array $config): string
    {
        $name = 'tool_db_'.bin2hex(random_bytes(8));

        if (! empty($config['ssh'])) {
            $handle = $this->sshTunnel->open(
                (array) $config['ssh'],
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 5432),
            );
            $this->tunnels[$name] = $handle;
            // Point the DB connection at the tunnel's local end.
            $config['host'] = '127.0.0.1';
            $config['port'] = $handle->localPort;
        }

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

        if (isset($this->tunnels[$name])) {
            $this->tunnels[$name]->close();
            unset($this->tunnels[$name]);
        }
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
