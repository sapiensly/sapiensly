<?php

namespace App\Support\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Holds the current tenant scope and pushes it onto the `tenant` connection as
 * the RLS GUCs (`app.organization_id` / `app.user_id`) read by the
 * tenant_isolation policies.
 *
 * Pooling note: {@see self::apply()} sets the GUCs at SESSION level, which is
 * correct when the `tenant` connection is dedicated for the request/worker —
 * i.e. a direct connection or Supabase's SESSION pooler (configure
 * TENANT_DB_HOST/PORT accordingly). If the tenant role must go through a
 * TRANSACTION pooler, wrap tenant work in {@see self::runScoped()} instead so
 * the GUCs are set transaction-locally alongside the queries.
 *
 * Registered as a singleton so HTTP middleware, queue middleware and account
 * switching share one instance per request/worker.
 */
class TenantContext
{
    private ?string $organizationId = null;

    private ?int $userId = null;

    public function set(?string $organizationId, ?int $userId): void
    {
        $this->organizationId = $organizationId;
        $this->userId = $userId;
        $this->apply();
    }

    /**
     * Clear the scope. The connection then has empty GUCs, so RLS yields zero
     * rows (fail-closed) until a context is set again.
     */
    public function forget(): void
    {
        $this->organizationId = null;
        $this->userId = null;
        $this->apply();
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function hasContext(): bool
    {
        return $this->organizationId !== null || $this->userId !== null;
    }

    /**
     * Push the current scope onto the tenant connection at session level.
     */
    public function apply(): void
    {
        $this->setConfig($this->connection(), local: false);
    }

    /**
     * Run a callback with the scope applied transaction-locally — the correct
     * mechanism under a transaction pooler. Keep external/long-running calls
     * (LLM, HTTP) OUTSIDE this closure so the transaction stays short.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runScoped(callable $callback): mixed
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $callback) {
            $this->setConfig($connection, local: true);

            return $callback();
        });
    }

    private function setConfig(ConnectionInterface $connection, bool $local): void
    {
        $connection->statement(
            'select set_config(?, ?, ?), set_config(?, ?, ?)',
            [
                'app.organization_id', $this->organizationId ?? '', $local,
                'app.user_id', $this->userId === null ? '' : (string) $this->userId, $local,
            ]
        );
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(Schemas::TENANT_CONNECTION);
    }
}
