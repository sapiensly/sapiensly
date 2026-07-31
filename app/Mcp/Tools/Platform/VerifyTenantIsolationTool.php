<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Services\Platform\TenantIsolationVerifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Verify that tenant isolation is actually ENFORCED, not merely declared. For every table the application treats as tenant data, this checks against the live Postgres catalog: is the table in the tenant schema, is Row-Level Security enabled, does the tenant_isolation policy exist, is the fill_tenant_key trigger attached, are the organization_id/user_id columns there, can the tenant role read it, and has the platform role been revoked. It also flags tenant-schema tables the application does not know about. The failure this catches — a table added to the map whose migration forgot to enable RLS — reads and writes perfectly in development and leaks every organization\'s rows in production. Read-only; inspects the catalog, never tenant data. Run it after any migration that touches tenant tables.')]
class VerifyTenantIsolationTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'only_problems' => ['sometimes', 'boolean'],
        ]);

        $result = app(TenantIsolationVerifier::class)->verify();

        if (! $result['supported']) {
            return Response::error($result['issues'][0] ?? 'Tenant isolation cannot be verified on this database.');
        }

        $onlyProblems = (bool) ($validated['only_problems'] ?? true);

        $tables = $onlyProblems
            ? array_values(array_filter($result['tables'], static fn (array $table) => $table['ok'] === false))
            : $result['tables'];

        return Response::json([
            'ok' => $result['ok'],
            'checked_tables' => $result['checked'],
            'failing_tables' => $result['failing'],
            'roles_present' => $result['roles'],
            'issues' => $result['issues'],
            'tables' => $tables,
            'showing' => $onlyProblems ? 'only tables with problems' : 'every checked table',
            'verdict' => $result['ok']
                ? 'Every declared tenant table is protected by RLS with its policy, trigger and grants in place.'
                : 'Isolation drift found — the tables listed above are NOT fully protected. Treat this as a data-exposure issue, not a lint warning.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'only_problems' => $schema->boolean()->description('Return only failing tables (default true). Pass false for the full table-by-table report.'),
        ];
    }
}
