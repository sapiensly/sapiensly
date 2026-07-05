<?php

namespace App\Services\Connected;

use App\Models\Integration;
use App\Models\User;
use App\Services\Tools\McpClient;
use App\Support\Tenancy\TenantCache;
use Illuminate\Support\Str;

/**
 * Pre-profiled knowledge of an MCP integration (optimization L3). The timing
 * telemetry showed a slow model burning ~100s of pure thinking across repeated
 * sample_mcp_tool rounds just to DISCOVER which tool to read and what its rows
 * look like — while the RPCs themselves took <1s. This catalog collapses those
 * rounds: the tool list is fetched once and cached, and every successful
 * connected read writes back the observed row shape, so the next build sees
 * "here are the tools, here are the row fields of the ones we've read" in its
 * FIRST tool result and can go straight to add_connected_object.
 *
 * Cached via TenantCache scoped explicitly to the integration's owner (never a
 * shared key), tolerating a missing cache backend by falling through to live.
 */
class IntegrationCatalog
{
    private const TOOLS_TTL_SECONDS = 21_600;    // 6h — tool lists change rarely

    private const SHAPES_TTL_SECONDS = 604_800;  // 7d — refreshed on every read

    public function __construct(
        private readonly McpClient $mcp,
        private readonly TenantCache $cache,
    ) {}

    /**
     * The server's tools (compact: name, trimmed description, required args +
     * numeric bounds), cached per integration + acting user (an OAuth server
     * may expose different tools per grant).
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException on connection/auth/protocol failure (uncached)
     */
    public function tools(Integration $integration, ?User $user): array
    {
        $key = "mcpcat:tools:{$integration->id}:u".($user?->id ?? 0);

        $cached = $this->cacheGet($integration, $key);
        if (is_array($cached)) {
            return $cached;
        }

        $tools = array_map(
            fn (array $tool): array => $this->compactTool($tool),
            $this->mcp->listTools($this->configFor($integration), $user),
        );

        $this->cachePut($integration, $key, $tools, self::TOOLS_TTL_SECONDS);

        return $tools;
    }

    /**
     * Record the observed row shape of one tool (write-through after any
     * successful connected read / modeling pass).
     *
     * @param  list<array{path: string, type: string}>  $fields
     */
    public function rememberShape(Integration $integration, string $toolName, ?string $collectionPath, array $fields): void
    {
        $shapes = $this->knownShapes($integration);
        $shapes[$toolName] = array_filter([
            'collection_path' => $collectionPath,
            'fields' => $fields,
        ], fn ($v) => $v !== null);

        $this->cachePut($integration, $this->shapesKey($integration), $shapes, self::SHAPES_TTL_SECONDS);
    }

    /**
     * Row shapes observed on this integration, keyed by tool name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function knownShapes(Integration $integration): array
    {
        $shapes = $this->cacheGet($integration, $this->shapesKey($integration));

        return is_array($shapes) ? $shapes : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(Integration $integration): array
    {
        return [
            'endpoint' => $integration->base_url,
            'integration_id' => $integration->id,
            'auth_type' => $integration->auth_type->isOAuth2() ? 'oauth2' : $integration->auth_type->value,
            'auth_config' => $integration->auth_config ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function compactTool(array $tool): array
    {
        $schema = is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : [];
        $args = [];
        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $args[$name] = array_filter([
                'type' => $spec['type'] ?? null,
                'minimum' => $spec['minimum'] ?? null,
                'maximum' => $spec['maximum'] ?? null,
                'enum' => is_array($spec['enum'] ?? null) ? array_slice($spec['enum'], 0, 8) : null,
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            'name' => (string) ($tool['name'] ?? ''),
            'description' => Str::limit(trim((string) ($tool['description'] ?? '')), 200),
            'required' => is_array($schema['required'] ?? null) ? $schema['required'] : null,
            'arguments' => $args !== [] ? $args : null,
            // The full schema stays available for argument clamping.
            'input_schema' => $schema !== [] ? $schema : [],
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function shapesKey(Integration $integration): string
    {
        return "mcpcat:shapes:{$integration->id}";
    }

    private function cacheGet(Integration $integration, string $key): mixed
    {
        try {
            return $this->scoped($integration)->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cachePut(Integration $integration, string $key, mixed $value, int $ttl): void
    {
        try {
            $this->scoped($integration)->put($key, $value, $ttl);
        } catch (\Throwable) {
            // Cache is an accelerator, never a dependency.
        }
    }

    private function scoped(Integration $integration): TenantCache
    {
        return $this->cache->forOwner($integration->organization_id, $integration->user_id);
    }
}
