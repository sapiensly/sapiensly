<?php

namespace App\Services\Tools;

use App\Enums\IntegrationAuthType;
use App\Models\Integration;
use App\Models\Tool;
use App\Services\ToolConfigService;
use Illuminate\Support\Str;

/**
 * Migrates legacy self-contained rest_api / graphql tools onto Connections.
 *
 * A legacy tool embeds its own base URL + auth in `config`. This distills that
 * connection into an Integration (deduped per owner + base URL + auth), points
 * the tool at it via `config.integration_id`, and strips the inline connection
 * fields — leaving only the operation on the tool. The tool's original config
 * is snapshotted under `config._legacy_connection` so the move is reversible.
 *
 * Idempotent: a tool already carrying an `integration_id` (or no base URL) is
 * skipped, so re-running is safe.
 */
class LegacyToolConnectionMigrator
{
    /**
     * The operation keys that stay on the tool per type — everything else in a
     * legacy config is connection data that moves to the integration.
     */
    private const OPERATION_KEYS = [
        'rest_api' => ['method', 'path', 'headers', 'request_body_template', 'response_mapping'],
        'graphql' => ['operation_type', 'operation', 'variables_template', 'response_mapping'],
        'database' => ['query_template', 'read_only'],
    ];

    public function __construct(private readonly ToolConfigService $configService) {}

    /**
     * @return array{migrated: int, skipped: int, integrations_created: int, details: array<int, string>}
     */
    public function migrate(bool $dryRun = false): array
    {
        $migrated = 0;
        $skipped = 0;
        $created = 0;
        $details = [];

        $tools = Tool::query()
            ->whereIn('type', ['rest_api', 'graphql', 'database'])
            ->get();

        foreach ($tools as $tool) {
            $type = $tool->type->value;
            $config = $this->configService->decryptConfig($tool->type, $tool->config ?? []);

            if (! empty($config['integration_id'])) {
                $skipped++;

                continue;
            }

            // Database tools distil into a database connection (a DSN), not an
            // HTTP one.
            if ($type === 'database') {
                if (empty($config['driver'])) {
                    $skipped++;

                    continue;
                }

                $dsn = $this->databaseDsn($config);
                $display = $this->databaseDisplayUrl($dsn);
                $integration = $this->findExistingDatabaseIntegration($tool, $dsn);
                $reused = $integration instanceof Integration;

                if (! $reused) {
                    $created++;
                    if (! $dryRun) {
                        $integration = $this->createDatabaseIntegration($tool, $display, $dsn);
                    }
                }

                $migrated++;
                $details[] = sprintf('database "%s" → %s connection (%s)', $tool->name, $reused ? 'existing' : 'new', $display);

                if (! $dryRun && $integration instanceof Integration) {
                    $tool->update(['config' => $this->rewriteConfig('database', $tool->config ?? [], $integration->id)]);
                }

                continue;
            }

            $baseUrl = $type === 'graphql' ? ($config['endpoint'] ?? '') : ($config['base_url'] ?? '');
            if (empty($baseUrl)) {
                $skipped++;

                continue;
            }

            [$authType, $authConfig] = $this->mapAuth($type, $config);

            $integration = $this->findExistingIntegration($tool, $baseUrl, $authType, $authConfig);
            $reused = $integration instanceof Integration;

            if (! $reused) {
                $created++;
                if (! $dryRun) {
                    $integration = $this->createIntegration($tool, $baseUrl, $authType, $authConfig);
                }
            }

            $migrated++;
            $details[] = sprintf(
                '%s "%s" → %s connection (%s)',
                $type,
                $tool->name,
                $reused ? 'existing' : 'new',
                $baseUrl,
            );

            if (! $dryRun && $integration instanceof Integration) {
                $tool->update(['config' => $this->rewriteConfig($type, $tool->config ?? [], $integration->id)]);
            }
        }

        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'integrations_created' => $created,
            'details' => $details,
        ];
    }

    /**
     * Restore migrated tools to their snapshotted self-contained config. The
     * integrations created during migration are left in place (harmless and
     * possibly shared); only the tools are reverted.
     *
     * @return array{reverted: int, details: array<int, string>}
     */
    public function rollback(bool $dryRun = false): array
    {
        $reverted = 0;
        $details = [];

        $tools = Tool::query()
            ->whereIn('type', ['rest_api', 'graphql', 'database'])
            ->get();

        foreach ($tools as $tool) {
            $config = $tool->config ?? [];
            if (empty($config['_legacy_connection'])) {
                continue;
            }

            $reverted++;
            $details[] = sprintf('%s "%s" reverted to inline config', $tool->type->value, $tool->name);

            if (! $dryRun) {
                $tool->update(['config' => $config['_legacy_connection']]);
            }
        }

        return ['reverted' => $reverted, 'details' => $details];
    }

    /**
     * Map a legacy tool's auth into an integration auth type + plaintext config.
     * A legacy `oauth2` tool carries a static access token, so it maps to a
     * bearer connection — preserving exactly the header it used to send.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: IntegrationAuthType, 1: array<string, mixed>}
     */
    private function mapAuth(string $toolType, array $config): array
    {
        $authConfig = $config['auth_config'] ?? [];

        return match ($config['auth_type'] ?? 'none') {
            'bearer' => [IntegrationAuthType::BearerToken, ['token' => $authConfig['token'] ?? '']],
            'basic' => [IntegrationAuthType::BasicAuth, [
                'username' => $authConfig['username'] ?? '',
                'password' => $authConfig['password'] ?? '',
            ]],
            'api_key' => $toolType === 'graphql'
                ? [IntegrationAuthType::ApiKey, [
                    'location' => 'header',
                    'name' => $authConfig['header_name'] ?? 'X-API-Key',
                    'value' => $authConfig['key'] ?? '',
                ]]
                : [IntegrationAuthType::ApiKey, [
                    'location' => $authConfig['location'] ?? 'header',
                    'name' => $authConfig['name'] ?? 'X-API-Key',
                    'value' => $authConfig['value'] ?? '',
                ]],
            'oauth2' => [IntegrationAuthType::BearerToken, ['token' => $authConfig['access_token'] ?? '']],
            default => [IntegrationAuthType::None, []],
        };
    }

    /**
     * @param  array<string, mixed>  $authConfig
     */
    private function findExistingIntegration(Tool $tool, string $baseUrl, IntegrationAuthType $authType, array $authConfig): ?Integration
    {
        return Integration::query()
            ->where('organization_id', $tool->organization_id)
            ->where('user_id', $tool->user_id)
            ->where('base_url', $baseUrl)
            ->where('auth_type', $authType)
            ->where('is_mcp', false)
            ->get()
            ->first(fn (Integration $integration): bool => ($integration->auth_config ?? []) === $authConfig);
    }

    /**
     * @param  array<string, mixed>  $authConfig
     */
    private function createIntegration(Tool $tool, string $baseUrl, IntegrationAuthType $authType, array $authConfig): Integration
    {
        return Integration::create([
            'user_id' => $tool->user_id,
            'organization_id' => $tool->organization_id,
            'visibility' => $tool->visibility,
            'name' => $tool->name.' connection',
            'slug' => Str::slug($tool->name).'-'.Str::lower(Str::random(6)),
            'description' => 'Auto-created from tool migration.',
            'base_url' => $baseUrl,
            'auth_type' => $authType,
            'auth_config' => $authConfig,
            'is_mcp' => false,
            'status' => 'active',
        ]);
    }

    /**
     * The DSN dict a legacy database tool embedded — the same shape a database
     * connection stores in auth_config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function databaseDsn(array $config): array
    {
        $dsn = ['driver' => $config['driver'] ?? 'pgsql'];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $dsn[$key] = $config[$key];
            }
        }

        return $dsn;
    }

    /**
     * @param  array<string, mixed>  $dsn
     */
    private function databaseDisplayUrl(array $dsn): string
    {
        $port = isset($dsn['port']) ? ':'.$dsn['port'] : '';

        return sprintf('%s://%s%s/%s', $dsn['driver'] ?? 'db', $dsn['host'] ?? '', $port, $dsn['database'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $dsn
     */
    private function findExistingDatabaseIntegration(Tool $tool, array $dsn): ?Integration
    {
        return Integration::query()
            ->where('organization_id', $tool->organization_id)
            ->where('user_id', $tool->user_id)
            ->where('kind', 'database')
            ->get()
            ->first(fn (Integration $integration): bool => ($integration->auth_config ?? []) === $dsn);
    }

    /**
     * @param  array<string, mixed>  $dsn
     */
    private function createDatabaseIntegration(Tool $tool, string $display, array $dsn): Integration
    {
        return Integration::create([
            'user_id' => $tool->user_id,
            'organization_id' => $tool->organization_id,
            'visibility' => $tool->visibility,
            'name' => $tool->name.' database',
            'slug' => Str::slug($tool->name).'-'.Str::lower(Str::random(6)),
            'description' => 'Auto-created from tool migration.',
            'base_url' => $display,
            'auth_type' => IntegrationAuthType::None,
            'auth_config' => $dsn,
            'kind' => 'database',
            'status' => 'active',
        ]);
    }

    /**
     * Keep the operation keys + the integration reference; snapshot the full
     * original (still field-encrypted) config for reversibility.
     *
     * @param  array<string, mixed>  $original
     * @return array<string, mixed>
     */
    private function rewriteConfig(string $type, array $original, string $integrationId): array
    {
        $kept = array_intersect_key($original, array_flip(self::OPERATION_KEYS[$type]));
        $kept['integration_id'] = $integrationId;
        $kept['_legacy_connection'] = $original;

        return $kept;
    }
}
