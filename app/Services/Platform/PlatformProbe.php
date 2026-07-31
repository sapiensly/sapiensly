<?php

namespace App\Services\Platform;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Live probes of the running platform — is Horizon up, is Reverb listening, how
 * deep are the queues, what does Postgres/Redis report about itself — plus the
 * dependency versions read straight off composer.lock / package.json.
 *
 * Every probe DEGRADES rather than throws: a health screen whose job is to tell
 * you something is down must not itself go down when it is. A dead subsystem
 * comes back as null/false, never as an exception.
 *
 * Shared by the admin Stack screen and the `platform_health` / `platform_stack`
 * MCP tools so the two can never disagree about what "ok" means.
 */
class PlatformProbe
{
    /** @var array<string, string>|null */
    private static ?array $composerVersions = null;

    /** @var array<string, string>|null */
    private static ?array $npmVersions = null;

    public function horizonRunning(): bool
    {
        try {
            return ! empty(app(MasterSupervisorRepository::class)->all());
        } catch (Throwable) {
            return false;
        }
    }

    public function reverbReachable(): bool
    {
        $host = (string) config('reverb.servers.reverb.host', '127.0.0.1');
        $port = (int) config('reverb.servers.reverb.port', 8080);

        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
            if (! $socket) {
                return false;
            }
            fclose($socket);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pending job count per configured queue, plus the failed-job total. The
     * queue names come from the Horizon supervisors so a queue added there is
     * covered here without a second list to remember.
     *
     * @return array{queues: array<string, int>, pending_total: int, failed: int, oldest_pending_seconds: ?int}
     */
    public function queueDepths(): array
    {
        $queues = [];

        foreach ($this->configuredQueues() as $queue) {
            try {
                $queues[$queue] = Queue::size($queue);
            } catch (Throwable) {
                // An unreachable broker is reported by the redis probe; a queue
                // we cannot measure is simply unknown, not zero.
                $queues[$queue] = -1;
            }
        }

        return [
            'queues' => $queues,
            'pending_total' => array_sum(array_filter($queues, fn (int $n) => $n > 0)),
            'failed' => $this->failedJobCount(),
            'oldest_pending_seconds' => $this->oldestPendingSeconds(),
        ];
    }

    /**
     * The distinct queue names Horizon is configured to supervise.
     *
     * @return list<string>
     */
    public function configuredQueues(): array
    {
        $names = [];

        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $names[] = (string) $queue;
            }
        }

        $environment = (array) config('horizon.environments.'.app()->environment(), []);
        foreach ($environment as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $names[] = (string) $queue;
            }
        }

        return array_values(array_unique($names !== [] ? $names : ['default']));
    }

    public function failedJobCount(): int
    {
        try {
            return (int) DB::connection('platform')->table('failed_jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * How long the oldest still-queued job has been waiting. A rising number
     * here is the signature of workers that died without draining.
     */
    public function oldestPendingSeconds(): ?int
    {
        try {
            $oldest = null;
            $connection = (string) config('queue.connections.redis.connection', 'default');

            foreach ($this->configuredQueues() as $queue) {
                // The tail of the list is the job that has waited longest.
                $raw = Redis::connection($connection)->lindex('queues:'.$queue, -1);
                if (! is_string($raw) || $raw === '') {
                    continue;
                }

                $payload = json_decode($raw, true);
                $pushedAt = $payload['pushedAt'] ?? null;
                if ($pushedAt === null) {
                    continue;
                }

                $age = (int) max(0, time() - (int) $pushedAt);
                $oldest = $oldest === null ? $age : max($oldest, $age);
            }

            return $oldest;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{reachable: bool, version: ?string, used_bytes: ?int, peak_bytes: ?int, clients: ?int, uptime_seconds: ?int}
     */
    public function redis(): array
    {
        try {
            $info = Redis::connection()->info();
        } catch (Throwable) {
            return [
                'reachable' => false,
                'version' => null,
                'used_bytes' => null,
                'peak_bytes' => null,
                'clients' => null,
                'uptime_seconds' => null,
            ];
        }

        // predis groups INFO by section, phpredis returns one flat map.
        $server = $info['Server'] ?? $info;
        $memory = $info['Memory'] ?? $info;
        $clients = $info['Clients'] ?? $info;

        return [
            'reachable' => true,
            'version' => isset($server['redis_version']) ? (string) $server['redis_version'] : null,
            'used_bytes' => isset($memory['used_memory']) ? (int) $memory['used_memory'] : null,
            'peak_bytes' => isset($memory['used_memory_peak']) ? (int) $memory['used_memory_peak'] : null,
            'clients' => isset($clients['connected_clients']) ? (int) $clients['connected_clients'] : null,
            'uptime_seconds' => isset($server['uptime_in_seconds']) ? (int) $server['uptime_in_seconds'] : null,
        ];
    }

    /**
     * @return array{driver: string, version: ?string, pgvector: ?string, connections: ?int, max_connections: ?int, size_bytes: ?int}
     */
    public function database(): array
    {
        $connection = $this->connection();

        if ($connection === null) {
            return [
                'driver' => 'unknown',
                'version' => null,
                'pgvector' => null,
                'connections' => null,
                'max_connections' => null,
                'size_bytes' => null,
            ];
        }

        $driver = $connection->getDriverName();

        if ($driver !== 'pgsql') {
            return [
                'driver' => $driver,
                'version' => null,
                'pgvector' => null,
                'connections' => null,
                'max_connections' => null,
                'size_bytes' => null,
            ];
        }

        return [
            'driver' => $driver,
            'version' => $this->shortPostgresVersion((string) ($this->scalar($connection, 'select version()') ?? '')),
            'pgvector' => $this->scalar($connection, "select extversion from pg_extension where extname = 'vector'"),
            'connections' => ($n = $this->scalar($connection, 'select count(*) from pg_stat_activity')) === null ? null : (int) $n,
            'max_connections' => ($n = $this->scalar($connection, 'show max_connections')) === null ? null : (int) $n,
            'size_bytes' => ($n = $this->scalar($connection, 'select pg_database_size(current_database())')) === null ? null : (int) $n,
        ];
    }

    public function postgresVersion(): ?string
    {
        return $this->database()['version'];
    }

    public function pgVectorVersion(): ?string
    {
        return $this->database()['pgvector'];
    }

    public function redisVersion(): ?string
    {
        return $this->redis()['version'];
    }

    /**
     * The installed version of a composer package, read from composer.lock so a
     * page load never shells out to composer.
     */
    public function composerVersion(string $package): ?string
    {
        if (self::$composerVersions === null) {
            self::$composerVersions = [];
            $lock = @file_get_contents(base_path('composer.lock'));

            if ($lock !== false) {
                $data = json_decode($lock, true) ?: [];
                foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package_) {
                    if (isset($package_['name'], $package_['version'])) {
                        self::$composerVersions[$package_['name']] = ltrim((string) $package_['version'], 'v');
                    }
                }
            }
        }

        return self::$composerVersions[$package] ?? null;
    }

    public function npmVersion(string $package): ?string
    {
        if (self::$npmVersions === null) {
            self::$npmVersions = [];
            $manifest = @file_get_contents(base_path('package.json'));

            if ($manifest !== false) {
                $data = json_decode($manifest, true) ?: [];
                self::$npmVersions = array_map(
                    static fn ($v) => ltrim((string) $v, '^~'),
                    array_merge(
                        (array) ($data['dependencies'] ?? []),
                        (array) ($data['devDependencies'] ?? []),
                    ),
                );
            }
        }

        return self::$npmVersions[$package] ?? null;
    }

    /** Reset the memoized manifest reads (tests). */
    public static function flushVersionCache(): void
    {
        self::$composerVersions = null;
        self::$npmVersions = null;
    }

    public function shortPostgresVersion(string $raw): string
    {
        if (preg_match('/\bPostgreSQL\s+([\d.]+)/i', $raw, $matches) === 1) {
            return $matches[1];
        }

        return $raw === '' ? 'unknown' : $raw;
    }

    private function connection(): ?Connection
    {
        try {
            return DB::connection();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function scalar(Connection $connection, string $sql, array $bindings = []): ?string
    {
        try {
            $row = $connection->selectOne($sql, $bindings);
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $first = array_values((array) $row)[0] ?? null;

        return $first === null ? null : (string) $first;
    }
}
