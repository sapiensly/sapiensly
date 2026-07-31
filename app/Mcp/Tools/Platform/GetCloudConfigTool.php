<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\CloudProvider;
use App\Services\Platform\PlatformProbe;
use App\Support\Tenancy\Schemas;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('The infrastructure this platform runs on: the storage and database providers configured (global and per-tenant BYODB, with credentials masked), pgvector availability, Redis state, and the tenancy layout — the two schemas with their table counts, the three database roles and whether each exists, and how many tenant tables have RLS switched on versus how many should. Read-only. For a table-by-table isolation verdict use verify_tenant_isolation.')]
class GetCloudConfigTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $probe = app(PlatformProbe::class);

        return Response::json([
            'database' => $probe->database(),
            'redis' => $probe->redis(),
            'tenancy' => $this->tenancy(),
            'providers' => $this->providers(),
            'queue' => [
                'default_connection' => config('queue.default'),
                'configured_queues' => $probe->configuredQueues(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenancy(): array
    {
        $connection = DB::connection('pgsql');

        if ($connection->getDriverName() !== 'pgsql') {
            return ['supported' => false];
        }

        $count = function (string $sql, array $bindings = []) use ($connection): ?int {
            try {
                $value = $connection->selectOne($sql, $bindings);

                return $value === null ? null : (int) (array_values((array) $value)[0] ?? 0);
            } catch (Throwable) {
                return null;
            }
        };

        $ownerRole = (string) config('database.connections.pgsql.username', 'postgres');
        $platformRole = (string) config('tenancy.platform_role', 'platform_app');
        $tenantRole = (string) config('tenancy.tenant_role', 'tenant_app');

        $presentRoles = [];
        try {
            $rows = $connection->select(
                'select rolname from pg_roles where rolname in (?, ?, ?)',
                [$ownerRole, $platformRole, $tenantRole],
            );
            $presentRoles = array_map(static fn ($row) => (string) $row->rolname, $rows);
        } catch (Throwable) {
            $presentRoles = [];
        }

        $protected = $count(
            'select count(*) from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relrowsecurity = true',
            [Schemas::TENANT],
        );

        return [
            'supported' => true,
            'schemas' => [
                [
                    'name' => Schemas::PLATFORM,
                    'scope' => 'control-plane',
                    'tables' => $count('select count(*) from pg_tables where schemaname = ?', [Schemas::PLATFORM]),
                    'rls' => false,
                ],
                [
                    'name' => Schemas::TENANT,
                    'scope' => 'tenant-data',
                    'tables' => $count('select count(*) from pg_tables where schemaname = ?', [Schemas::TENANT]),
                    'rls' => true,
                ],
            ],
            'roles' => [
                ['name' => $ownerRole, 'scope' => 'owner', 'present' => in_array($ownerRole, $presentRoles, true)],
                ['name' => $platformRole, 'scope' => 'platform', 'present' => in_array($platformRole, $presentRoles, true)],
                ['name' => $tenantRole, 'scope' => 'tenant', 'present' => in_array($tenantRole, $presentRoles, true)],
            ],
            'rls' => [
                'tables_protected' => $protected,
                'tables_expected' => count(Schemas::tenantTables()),
            ],
        ];
    }

    /**
     * Configured storage/database providers, credentials masked. Global rows
     * are the platform's own; the rest belong to tenants that brought their own.
     *
     * @return array<string, mixed>
     */
    private function providers(): array
    {
        try {
            $providers = CloudProvider::query()->get();
        } catch (Throwable) {
            return ['global' => [], 'tenant_owned' => 0];
        }

        $present = fn (CloudProvider $provider) => [
            'id' => $provider->id,
            'kind' => $provider->kind,
            'driver' => $provider->driver,
            'name' => $provider->display_name,
            'visibility' => $provider->visibility,
            'status' => $provider->status,
            'is_default' => (bool) $provider->is_default,
        ];

        $global = $providers->filter(fn (CloudProvider $p) => $p->organization_id === null);

        return [
            'global' => $global->map($present)->values(),
            'tenant_owned' => $providers->count() - $global->count(),
            'note' => 'Credentials are never returned. Tenant-owned providers (BYODB / bring-your-own storage) are counted, not listed.',
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
