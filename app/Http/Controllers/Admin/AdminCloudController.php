<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudProvider;
use App\Services\CloudProviderService;
use App\Services\VectorStoreSchema;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Read-only cloud status for the admin-v2 Cloud screen. The handoff is
 * explicit that this page is a dashboard, not a settings form — writes live
 * on the legacy `/admin/system/global-cloud` pages until cutover.
 *
 * The controller reads the global CloudProvider rows for display values
 * (driver, bucket, region) and runs a handful of lightweight Postgres
 * queries for sizeBytes / connection counts / pgvector indexes. Expensive
 * operations like S3 ListObjects are intentionally not performed per request
 * — capacity is surfaced as null and the UI shows an em-dash.
 */
class AdminCloudController extends Controller
{
    public function __construct(
        private CloudProviderService $cloud,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Cloud', [
            'storage' => $this->readStorage(),
            'database' => $this->readDatabase(),
            'pgvector' => $this->readPgVector(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStorage(): ?array
    {
        $provider = $this->cloud->getGlobalStorage();
        if ($provider === null) {
            return null;
        }

        $credentials = $provider->credentials ?? [];

        return [
            'driver' => $provider->driver,
            'bucket' => $credentials['bucket'] ?? '—',
            'region' => $credentials['region'] ?? '—',
            // Capacity requires a ListObjects call per bucket — intentionally
            // not performed on every page load. Surface null; a follow-up can
            // wire a nightly job that caches the roll-up.
            'usedBytes' => null,
            'totalBytes' => null,
            'lastBackupAt' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readDatabase(): ?array
    {
        $connection = $this->resolveDatabaseConnection();
        if ($connection === null) {
            return null;
        }

        $engine = $connection->getDriverName() === 'pgsql' ? 'postgres' : 'mysql';
        $database = $connection->getDatabaseName();
        $host = (string) config("database.connections.{$connection->getName()}.host", '—');

        $version = $this->safeScalar($connection, 'select version()');
        $sizeBytes = null;
        $active = null;
        $max = null;

        if ($engine === 'postgres' && $database !== null) {
            $sizeBytes = (int) ($this->safeScalar(
                $connection,
                'select pg_database_size(?)',
                [$database],
            ) ?? 0);

            $active = (int) ($this->safeScalar(
                $connection,
                'select count(*) from pg_stat_activity where datname = ?',
                [$database],
            ) ?? 0);

            $max = (int) ($this->safeScalar(
                $connection,
                'select setting::int from pg_settings where name = ?',
                ['max_connections'],
            ) ?? 0);
        }

        return [
            'engine' => $engine,
            'version' => $this->shortVersion((string) $version),
            'host' => $host,
            'sizeBytes' => $sizeBytes,
            'connections' => [
                'active' => $active ?? 0,
                'max' => $max ?? 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPgVector(): array
    {
        $connection = $this->resolveDatabaseConnection();
        if ($connection === null || $connection->getDriverName() !== 'pgsql') {
            return [
                'enabled' => false,
                'version' => null,
                'indexCount' => 0,
                'vectorCount' => 0,
                'sizeBytes' => 0,
                'indexes' => [],
            ];
        }

        $version = $this->safeScalar(
            $connection,
            "select extversion from pg_extension where extname = 'vector'",
        );
        $enabled = $version !== null;

        if (! $enabled) {
            return [
                'enabled' => false,
                'version' => null,
                'indexCount' => 0,
                'vectorCount' => 0,
                'sizeBytes' => 0,
                'indexes' => [],
            ];
        }

        $indexes = $this->readVectorIndexes($connection);
        $vectorCount = 0;
        $sizeBytes = 0;

        try {
            $rows = $connection->select(<<<'SQL'
                select sum(n_live_tup)::bigint as total, sum(pg_total_relation_size(relid))::bigint as bytes
                from pg_stat_user_tables
                where relname = ?
            SQL, [VectorStoreSchema::TABLE]);
            if (! empty($rows)) {
                $vectorCount = (int) ($rows[0]->total ?? 0);
                $sizeBytes = (int) ($rows[0]->bytes ?? 0);
            }
        } catch (Throwable) {
            // Vector table may not be installed yet — silent degrade.
        }

        return [
            'enabled' => true,
            'version' => $version,
            'indexCount' => count($indexes),
            'vectorCount' => $vectorCount,
            'sizeBytes' => $sizeBytes,
            'indexes' => $indexes,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readVectorIndexes(Connection $connection): array
    {
        try {
            // pg_indexes lists every index; filter to those that reference
            // an ivfflat/hnsw operator class which is pgvector's signature.
            $rows = $connection->select(<<<'SQL'
                select
                    i.indexrelname as name,
                    c.relname as table_name,
                    pg_relation_size(i.indexrelid) as size_bytes,
                    am.amname as metric,
                    st.n_live_tup as rows
                from pg_stat_user_indexes i
                join pg_class c on c.oid = i.relid
                join pg_index ix on ix.indexrelid = i.indexrelid
                join pg_opclass oc on oc.oid = ix.indclass[0]
                join pg_am am on am.oid = oc.opcmethod
                left join pg_stat_user_tables st on st.relid = i.relid
                where am.amname in ('ivfflat', 'hnsw')
                order by i.indexrelname
            SQL);

            return array_map(function ($r) {
                return [
                    'name' => (string) $r->name,
                    'table' => (string) $r->table_name,
                    'dim' => null,
                    'metric' => $this->mapOpClassToMetric((string) $r->metric),
                    'rows' => (int) ($r->rows ?? 0),
                ];
            }, $rows);
        } catch (Throwable) {
            return [];
        }
    }

    private function mapOpClassToMetric(string $amname): string
    {
        // pgvector uses two index methods; mapping to the three public
        // metric names (cosine/l2/ip) requires introspecting the operator
        // class itself. Without that information we surface the method as
        // the metric for now — good enough for the read-only screen.
        return $amname;
    }

    private function resolveDatabaseConnection(): ?Connection
    {
        $provider = $this->cloud->getGlobalDatabase();
        if ($provider instanceof CloudProvider) {
            try {
                return $this->cloud->buildConnection($provider);
            } catch (Throwable) {
                return DB::connection();
            }
        }

        try {
            return DB::connection();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function safeScalar(Connection $connection, string $sql, array $bindings = []): mixed
    {
        try {
            $result = $connection->selectOne($sql, $bindings);
        } catch (Throwable) {
            return null;
        }

        if ($result === null) {
            return null;
        }

        // First column, irrespective of alias.
        $first = array_values((array) $result)[0] ?? null;

        return $first;
    }

    private function shortVersion(string $raw): string
    {
        // Postgres returns a verbose banner — keep just the version number.
        if (preg_match('/\bPostgreSQL\s+([\d.]+)/i', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }
}
