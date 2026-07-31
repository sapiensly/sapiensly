<?php

namespace App\Services\Platform;

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks that what the code BELIEVES about tenant isolation is what Postgres is
 * actually enforcing.
 *
 * {@see Schemas::TENANT_TABLES} is the declaration; RLS, the policy, the
 * auto-fill trigger and the role grants are the enforcement, and they are put in
 * place by hand-written migrations. Nothing keeps the two in step: a tenant
 * table added to the map whose migration forgot `ENABLE ROW LEVEL SECURITY`
 * reads and writes perfectly well in development and leaks every organization's
 * rows in production. This is the check that says so out loud.
 *
 * Read-only, and it inspects the catalog rather than the data — it never reads
 * a tenant row to find out whether tenant rows are protected.
 */
class TenantIsolationVerifier
{
    public const POLICY = 'tenant_isolation';

    public const TRIGGER = 'fill_tenant_key';

    /**
     * @return array{
     *     supported: bool,
     *     ok: bool,
     *     checked: int,
     *     failing: int,
     *     roles: array<string, bool>,
     *     tables: list<array<string, mixed>>,
     *     issues: list<string>,
     * }
     */
    public function verify(): array
    {
        $connection = DB::connection('pgsql');

        if ($connection->getDriverName() !== 'pgsql') {
            return [
                'supported' => false,
                'ok' => false,
                'checked' => 0,
                'failing' => 0,
                'roles' => [],
                'tables' => [],
                'issues' => ['Row-Level Security can only be verified on PostgreSQL.'],
            ];
        }

        $expected = Schemas::tenantTables();

        $rls = $this->rowSecurityFlags($connection);
        $policies = $this->policyNames($connection);
        $triggers = $this->triggerNames($connection);
        $columns = $this->tenantKeyColumns($connection);
        $grants = $this->tablePrivileges($connection);

        $tenantRole = (string) config('tenancy.tenant_role', 'tenant_app');
        $platformRole = (string) config('tenancy.platform_role', 'platform_app');

        $tables = [];
        $issues = [];
        $failing = 0;

        foreach ($expected as $table) {
            $problems = [];

            $present = array_key_exists($table, $rls);
            if (! $present) {
                $problems[] = 'missing from the tenant schema';
            } else {
                if ($rls[$table] === false) {
                    $problems[] = 'row-level security is DISABLED';
                }
                if (! in_array(self::POLICY, $policies[$table] ?? [], true)) {
                    $problems[] = 'no '.self::POLICY.' policy';
                }
                if (! in_array(self::TRIGGER, $triggers[$table] ?? [], true)) {
                    $problems[] = 'no '.self::TRIGGER.' trigger';
                }
                foreach (['organization_id', 'user_id'] as $column) {
                    if (! in_array($column, $columns[$table] ?? [], true)) {
                        $problems[] = "no {$column} column";
                    }
                }
                if (! in_array($tenantRole, $grants[$table] ?? [], true)) {
                    $problems[] = "{$tenantRole} has no privileges (the runtime role cannot read it)";
                }
                if (in_array($platformRole, $grants[$table] ?? [], true)) {
                    $problems[] = "{$platformRole} still has privileges (should be revoked)";
                }
            }

            $ok = $problems === [];
            if (! $ok) {
                $failing++;
                $issues[] = $table.': '.implode('; ', $problems);
            }

            $tables[] = [
                'table' => $table,
                'ok' => $ok,
                'present' => $present,
                'rls_enabled' => $rls[$table] ?? false,
                'policy' => $present && in_array(self::POLICY, $policies[$table] ?? [], true),
                'trigger' => $present && in_array(self::TRIGGER, $triggers[$table] ?? [], true),
                'problems' => $problems,
            ];
        }

        // A tenant-schema table nobody declared is the same drift seen from the
        // other side: it is protected by nothing the application knows about.
        foreach (array_keys($rls) as $table) {
            if (! in_array($table, $expected, true)) {
                $issues[] = $table.': present in the tenant schema but absent from Schemas::TENANT_TABLES';
            }
        }

        $roles = [];
        foreach ($this->presentRoles($connection, [
            (string) config('database.connections.pgsql.username', 'postgres'),
            $platformRole,
            $tenantRole,
        ]) as $role => $exists) {
            $roles[$role] = $exists;
        }

        return [
            'supported' => true,
            'ok' => $issues === [],
            'checked' => count($expected),
            'failing' => $failing,
            'roles' => $roles,
            'tables' => $tables,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function rowSecurityFlags(object $connection): array
    {
        $rows = $this->select($connection, <<<'SQL'
            select c.relname as table_name, c.relrowsecurity as enabled
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = ? and c.relkind = 'r'
        SQL, [Schemas::TENANT]);

        $flags = [];
        foreach ($rows as $row) {
            $flags[(string) $row->table_name] = (bool) $row->enabled;
        }

        return $flags;
    }

    /**
     * @return array<string, list<string>>
     */
    private function policyNames(object $connection): array
    {
        return $this->group($this->select($connection, <<<'SQL'
            select tablename as table_name, policyname as value
            from pg_policies
            where schemaname = ?
        SQL, [Schemas::TENANT]));
    }

    /**
     * @return array<string, list<string>>
     */
    private function triggerNames(object $connection): array
    {
        return $this->group($this->select($connection, <<<'SQL'
            select c.relname as table_name, t.tgname as value
            from pg_trigger t
            join pg_class c on c.oid = t.tgrelid
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = ? and not t.tgisinternal
        SQL, [Schemas::TENANT]));
    }

    /**
     * @return array<string, list<string>>
     */
    private function tenantKeyColumns(object $connection): array
    {
        return $this->group($this->select($connection, <<<'SQL'
            select table_name, column_name as value
            from information_schema.columns
            where table_schema = ? and column_name in ('organization_id', 'user_id')
        SQL, [Schemas::TENANT]));
    }

    /**
     * @return array<string, list<string>>
     */
    private function tablePrivileges(object $connection): array
    {
        return $this->group($this->select($connection, <<<'SQL'
            select table_name, grantee as value
            from information_schema.role_table_grants
            where table_schema = ? and privilege_type = 'SELECT'
        SQL, [Schemas::TENANT]));
    }

    /**
     * @param  list<string>  $candidates
     * @return array<string, bool>
     */
    private function presentRoles(object $connection, array $candidates): array
    {
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $found = array_map(
            static fn ($row) => (string) $row->rolname,
            $this->select($connection, "select rolname from pg_roles where rolname in ({$placeholders})", $candidates),
        );

        $result = [];
        foreach ($candidates as $role) {
            $result[$role] = in_array($role, $found, true);
        }

        return $result;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, list<string>>
     */
    private function group(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->table_name][] = (string) $row->value;
        }

        return array_map(static fn (array $values) => array_values(array_unique($values)), $grouped);
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<object>
     */
    private function select(object $connection, string $sql, array $bindings = []): array
    {
        try {
            /** @var Connection $connection */
            return $connection->select($sql, $bindings);
        } catch (Throwable) {
            return [];
        }
    }
}
