<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Services\CloudProviderService;
use App\Support\Tenancy\TenantCache;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Destroying a tenant, on purpose and for good.
 *
 * Suspending an organization is a soft delete: the rows stay, hidden behind
 * RLS, and it can be undone. That is the right default and the wrong answer to
 * "delete my data" — until now there was nothing else, so a deletion request
 * left every record, document, conversation and file exactly where it was.
 *
 * The table list is asked of the DATABASE, not kept here. A hand-maintained
 * constant is wrong the day somebody adds a table and does not think of this
 * file, and the failure is silent: the purge reports success while the new
 * table still holds the tenant's rows. So the question is "which tables carry
 * an organization_id", answered fresh on every run.
 *
 * Two tables are kept deliberately, and they are named in the report rather
 * than quietly skipped — see {@see self::KEPT}.
 */
class OrganizationPurge
{
    /**
     * What survives a purge, and why.
     *
     * `users` — a person is not the property of an organization. Their
     * membership goes and their active org is unset, but deleting the account
     * of somebody who also belongs elsewhere would destroy a third party's
     * access along with the tenant.
     *
     * `platform_audit_log` — it is the record that the purge happened, and by
     * whom. Deleting the evidence of a deletion is the one thing a controller
     * processing a deletion request must NOT do.
     */
    private const KEPT = ['users', 'platform_audit_log'];

    /** Rows per statement: small enough to stay out of everybody's way. */
    private const BATCH = 1000;

    public function __construct(private readonly CloudProviderService $cloudProviders) {}

    /**
     * What a purge would remove, without removing any of it.
     *
     * @return array{tables: array<string, int>, rows: int, files: int, kept: list<string>}
     */
    public function preview(Organization $organization): array
    {
        $tables = [];
        $rows = 0;

        foreach ($this->tablesCarryingTenantKey() as $table) {
            $count = $this->owner()->table($table)
                ->where('organization_id', $organization->id)
                ->count();

            if ($count > 0) {
                $tables[$table] = $count;
                $rows += $count;
            }
        }

        return [
            'tables' => $tables,
            'rows' => $rows,
            'files' => count($this->fileTargets($organization)),
            'kept' => self::KEPT,
        ];
    }

    /**
     * @return array{tables: array<string, int>, rows: int, files: int, files_failed: int, stuck: list<string>, kept: list<string>}
     */
    public function purge(Organization $organization, ?string $actorId = null): array
    {
        // Bytes first: the rows are the only map to them, and a row deleted
        // before its file is a file nobody can ever find again.
        [$filesDeleted, $filesFailed] = $this->deleteFiles($organization);

        $deleted = [];
        $remaining = $this->tablesCarryingTenantKey();

        // Foreign keys mean the order matters, and the order is not knowable
        // from here — it changes as the schema does. So: keep sweeping, drop
        // whatever will drop this pass, and stop when a whole pass moves
        // nothing. Self-correcting, and it never needs a list kept by hand.
        while ($remaining !== []) {
            $stillStuck = [];
            $progress = false;

            foreach ($remaining as $table) {
                try {
                    $count = $this->deleteFrom($table, $organization->id);
                    $deleted[$table] = ($deleted[$table] ?? 0) + $count;
                    $progress = true;
                } catch (Throwable $e) {
                    $stillStuck[] = $table;
                    Log::debug('Purge deferred a table', ['table' => $table, 'reason' => $e->getMessage()]);
                }
            }

            $remaining = $stillStuck;

            if (! $progress) {
                break;
            }
        }

        $this->releasePeople($organization);
        $this->forgetCache($organization);

        // The organization row itself, last: while it exists, everything above
        // is still findable if the run dies halfway and has to be repeated.
        if ($remaining === []) {
            Organization::withTrashed()->whereKey($organization->id)->forceDelete();
        }

        return [
            'tables' => array_filter($deleted),
            'rows' => array_sum($deleted),
            'files' => $filesDeleted,
            'files_failed' => $filesFailed,
            // Named, never swallowed. A purge that left rows behind and said
            // nothing is worse than one that failed outright.
            'stuck' => array_values($remaining),
            'kept' => self::KEPT,
            'actor_id' => $actorId,
        ];
    }

    /**
     * Every table that carries a tenant key, asked of the database itself.
     *
     * @return list<string> Schema-qualified, e.g. `tenant.records`.
     */
    private function tablesCarryingTenantKey(): array
    {
        $rows = $this->owner()->select(
            'select table_schema, table_name from information_schema.columns
             where column_name = ? and table_schema in (?, ?)
             order by table_schema, table_name',
            ['organization_id', 'platform', 'tenant'],
        );

        $tables = [];

        foreach ($rows as $row) {
            if (in_array($row->table_name, self::KEPT, true)) {
                continue;
            }

            $tables[] = $row->table_schema.'.'.$row->table_name;
        }

        return $tables;
    }

    private function deleteFrom(string $table, string $organizationId): int
    {
        // The spatie pivots are keyed by (model, role, team) and have no `id`,
        // so they cannot be batched by key. They hold one row per assignment,
        // which is small by construction — deleting them in one statement is
        // not the lock the batching exists to avoid.
        if (! $this->hasIdColumn($table)) {
            return $this->owner()->table($table)
                ->where('organization_id', $organizationId)
                ->delete();
        }

        $total = 0;

        do {
            // Batched by primary key rather than a bare LIMIT: Postgres has no
            // LIMIT on DELETE, and an unbounded one over a year of a busy
            // tenant is a lock every other write queues behind.
            $ids = $this->owner()->table($table)
                ->where('organization_id', $organizationId)
                ->limit(self::BATCH)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += $this->owner()->table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::BATCH);

        return $total;
    }

    /** @var array<string, bool> */
    private array $hasId = [];

    private function hasIdColumn(string $qualified): bool
    {
        return $this->hasId[$qualified] ??= (function () use ($qualified): bool {
            [$schema, $table] = explode('.', $qualified, 2);

            return $this->owner()->select(
                'select 1 from information_schema.columns
                 where table_schema = ? and table_name = ? and column_name = ?',
                [$schema, $table, 'id'],
            ) !== [];
        })();
    }

    /**
     * The people. Their memberships to this tenant are gone with the rest; what
     * is left is somebody whose ACTIVE organization no longer exists, which
     * would strand them on a dead context at next sign-in.
     */
    private function releasePeople(Organization $organization): void
    {
        $this->owner()->table('platform.users')
            ->where('organization_id', $organization->id)
            ->update(['organization_id' => null]);
    }

    /**
     * Redis holds no foreign keys and no RLS: a tenant's cached values are
     * namespaced by {@see TenantCache} and would otherwise
     * outlive everything they were derived from.
     */
    private function forgetCache(Organization $organization): void
    {
        try {
            $prefix = config('database.redis.options.prefix', '');
            $pattern = $prefix.'*t:org:'.$organization->id.':*';

            foreach (Redis::keys($pattern) as $key) {
                Redis::del(str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
            }
        } catch (Throwable $e) {
            // A stale cache key for a tenant that no longer exists is
            // unreachable anyway — not a reason to fail the purge.
            Log::warning('Purge could not clear the tenant cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Every stored object belonging to this tenant, as {disk, path} pairs.
     *
     * Four tables name their own disk; documents resolve theirs the way the
     * service that wrote them does, which may be the tenant's OWN bucket.
     *
     * @return list<array{disk: ?string, path: string, org: string, user: ?int}>
     */
    private function fileTargets(Organization $organization): array
    {
        $targets = [];

        $withDisk = [
            'tenant.app_exports',
            'tenant.app_files',
            'tenant.chat_attachments',
            'tenant.widget_attachments',
        ];

        foreach ($withDisk as $table) {
            foreach ($this->owner()->table($table)
                ->where('organization_id', $organization->id)
                ->select('disk', 'storage_path')
                ->cursor() as $row) {
                if (! empty($row->storage_path)) {
                    $targets[] = ['disk' => $row->disk, 'path' => (string) $row->storage_path, 'org' => $organization->id, 'user' => null];
                }
            }
        }

        foreach (['tenant.documents', 'tenant.knowledge_base_documents'] as $table) {
            foreach ($this->owner()->table($table)
                ->where('organization_id', $organization->id)
                ->select('file_path', 'user_id')
                ->cursor() as $row) {
                if (! empty($row->file_path)) {
                    $targets[] = ['disk' => null, 'path' => (string) $row->file_path, 'org' => $organization->id, 'user' => $row->user_id ?? null];
                }
            }
        }

        return $targets;
    }

    /**
     * @return array{0: int, 1: int} deleted, failed
     */
    private function deleteFiles(Organization $organization): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($this->fileTargets($organization) as $target) {
            try {
                $disk = $target['disk'] !== null
                    ? Storage::disk($target['disk'])
                    : $this->cloudProviders->diskForOwnerOrFallback($target['org'], $target['user']);

                if ($disk->exists($target['path'])) {
                    $disk->delete($target['path']);
                }

                $deleted++;
            } catch (Throwable $e) {
                // Counted, not hidden: bytes left on a bucket are exactly what
                // somebody asking for deletion needs to hear about.
                $failed++;
                Log::warning('Purge could not delete a stored object', [
                    'path' => $target['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$deleted, $failed];
    }

    /**
     * The owner connection: it bypasses RLS, which is the point. Setting a
     * tenant context for an organization being destroyed would be arranging to
     * see it while removing the reason it is visible.
     */
    private function owner(): Connection
    {
        return DB::connection('pgsql');
    }
}
