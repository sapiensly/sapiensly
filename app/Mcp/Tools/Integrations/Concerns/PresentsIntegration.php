<?php

namespace App\Mcp\Tools\Integrations\Concerns;

use App\Models\Integration;
use App\Services\Integrations\IntegrationService;

/**
 * Shared presentation for the integration (connection) management MCP tools.
 * Credentials in `auth_config` are masked via the same redactor the web UI
 * uses (never returned in plaintext).
 */
trait PresentsIntegration
{
    /**
     * The JSON shape returned for a single integration.
     *
     * @return array<string, mixed>
     */
    protected function integrationPayload(Integration $integration): array
    {
        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'slug' => $integration->slug,
            'description' => $integration->description,
            'kind' => $integration->kind?->value,
            'base_url' => $integration->base_url,
            'is_mcp' => $integration->is_mcp,
            'auth_type' => $integration->auth_type?->value,
            'auth_config' => app(IntegrationService::class)->maskAuthConfig($integration),
            'default_headers' => $integration->default_headers,
            'status' => $integration->status,
            'visibility' => $integration->visibility?->value,
            'allow_insecure_tls' => $integration->allow_insecure_tls,
            'last_test_status' => $integration->last_test_status,
            'last_tested_at' => $integration->last_tested_at?->toIso8601String(),
            'requests_count' => $integration->requests()->count(),
            'environments' => $integration->environments()
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($env) => [
                    'id' => $env->id,
                    'name' => $env->name,
                    'is_active' => $env->id === $integration->active_environment_id,
                ])->values(),
        ];
    }
}
