<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pin `search_path` as a ROLE default for the runtime roles so it survives a
 * PgBouncer TRANSACTION pooler.
 *
 * Laravel sets `search_path` once per session (the connection's `search_path`
 * config → a `SET search_path` statement at connect time). Under transaction
 * pooling each transaction may be handed a different server backend, so that
 * session-level SET is lost and an unqualified table reference inside a later
 * transaction fails ("relation does not exist") — which then surfaces on the
 * NEXT statement as "SQLSTATE[25P02]: current transaction is aborted" (the error
 * seen when creating an app, in AppManifestService::createVersion).
 *
 * `ALTER ROLE … SET search_path` is applied to every NEW backend the role opens,
 * regardless of pool mode, so the path is always present. This is a robustness
 * fix; the fully-correct posture is still to run the runtime connections through
 * a SESSION pooler (the tenant connection ALSO needs that for its per-request RLS
 * GUCs, which a role default cannot provide).
 *
 * Idempotent; runs as the owner (it must be able to ALTER ROLE). New backends
 * pick it up — recycle/reload PgBouncer (or let backends turn over) after deploy.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $platformRole = (string) config('database.connections.platform.username', 'platform_app');
        $tenantRole = (string) config('database.connections.tenant.username', 'tenant_app');

        $this->setSearchPath($platformRole, 'platform, public');
        $this->setSearchPath($tenantRole, 'tenant, public');
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            (string) config('database.connections.platform.username', 'platform_app'),
            (string) config('database.connections.tenant.username', 'tenant_app'),
        ] as $role) {
            if ($this->roleExists($role)) {
                DB::connection($this->connection)->statement(
                    'ALTER ROLE '.$this->quoteIdentifier($role).' RESET search_path'
                );
            }
        }
    }

    private function setSearchPath(string $role, string $searchPath): void
    {
        if (! $this->roleExists($role)) {
            return;
        }

        // search_path is a value list, not an identifier list; the schema names
        // here are static constants, never user input.
        DB::connection($this->connection)->statement(
            'ALTER ROLE '.$this->quoteIdentifier($role).' SET search_path TO '.$searchPath
        );
    }

    private function roleExists(string $role): bool
    {
        return DB::connection($this->connection)->scalar(
            'select 1 from pg_roles where rolname = ?',
            [$role]
        ) !== null;
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
