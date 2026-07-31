<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Connect, rotate, test or refresh an AI provider at the platform level. Actions: set_key (store or ROTATE the global API key — the previous one is overwritten and stops working immediately), test (probe the live connection using whatever key is configured, whether saved or from .env), sync_models (refresh the driver\'s catalog from its /models endpoint; only some drivers support it). The key is encrypted at rest, never echoed back, and the audit entry records that a rotation happened, not the value. Audited.')]
class ManageProviderKeyTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'driver' => ['required', 'string', 'max:60'],
            'action' => ['required', 'string', 'in:set_key,test,sync_models'],
            'api_key' => ['sometimes', 'nullable', 'string', 'min:16', 'max:500'],
            'url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $driver = $validated['driver'];
        $service = app(AiProviderService::class);

        if (! array_key_exists($driver, AiProviderService::DRIVER_LABELS)) {
            return Response::error(
                "Unknown driver '{$driver}'. Known drivers: ".implode(', ', array_keys(AiProviderService::DRIVER_LABELS))
            );
        }

        return match ($validated['action']) {
            'set_key' => $this->setKey($service, $actor, $driver, $validated['api_key'] ?? null, $validated['url'] ?? null),
            'test' => $this->test($service, $driver),
            'sync_models' => $this->syncModels($service, $actor, $driver),
        };
    }

    private function setKey(AiProviderService $service, User $actor, string $driver, ?string $apiKey, ?string $url): Response
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return Response::error('`api_key` is required for set_key.');
        }

        $rotated = $service->driverConfiguredSource($driver) !== null;

        $credentials = array_filter([
            'api_key' => trim($apiKey),
            'url' => $url,
            'rotated_at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');

        $provider = $service->upsertGlobalProviderForDriver($driver, $credentials);

        $this->audit(
            actor: $actor,
            summary: ($rotated ? 'Rotated' : 'Connected')." the global {$driver} API key",
            // No key material: the fact of the rotation is the auditable event.
            meta: ['driver' => $driver, 'rotated' => $rotated, 'custom_url' => $url !== null],
            targetType: 'ai_provider',
            targetId: $provider->id,
            targetLabel: $driver,
        );

        $probe = $service->testConfiguredDriver($driver);

        return Response::json([
            'action' => 'set_key',
            'driver' => $driver,
            'rotated' => $rotated,
            'connection_test' => [
                'success' => $probe['success'],
                'message' => $probe['message'],
            ],
            'note' => $rotated
                ? 'The previous key was overwritten and no longer works.'
                : 'Provider connected. Enable its models with manage_catalog_model before anything can route to them.',
        ]);
    }

    private function test(AiProviderService $service, string $driver): Response
    {
        if ($service->driverConfiguredSource($driver) === null) {
            return Response::error("No API key is configured for '{$driver}' — nothing to test. Connect one with action=set_key.");
        }

        $result = $service->testConfiguredDriver($driver);

        return Response::json([
            'action' => 'test',
            'driver' => $driver,
            'success' => $result['success'],
            'message' => $result['message'],
            'detail' => $result['detail'] ?? null,
        ]);
    }

    private function syncModels(AiProviderService $service, User $actor, string $driver): Response
    {
        if (! $service->isSyncable($driver)) {
            return Response::error(
                "'{$driver}' does not expose a syncable model list. Syncable drivers: ".implode(', ', AiProviderService::SYNCABLE_DRIVERS)
            );
        }

        $credentials = $service->resolveGlobalCredentials($driver);
        if (empty($credentials['api_key'] ?? null)) {
            return Response::error("Connect an API key for '{$driver}' first (action=set_key).");
        }

        $models = $service->fetchProviderModels($driver, $credentials);

        if ($models === []) {
            return Response::error("The provider returned no models — check the key for '{$driver}' and try again.");
        }

        $created = $service->syncDirectCatalogModels($driver, $models);

        $this->audit(
            actor: $actor,
            summary: "Synced {$driver} models: {$created} new of ".count($models).' returned',
            meta: ['driver' => $driver, 'fetched' => count($models), 'created' => $created],
            targetType: 'ai_catalog',
            targetLabel: $driver,
        );

        return Response::json([
            'action' => 'sync_models',
            'driver' => $driver,
            'fetched' => count($models),
            'newly_registered' => $created,
            'note' => 'New models arrive DISABLED. Turn on the ones you want with manage_catalog_model.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'driver' => $schema->string()->description('Provider driver: anthropic, openai, gemini, mistral, openrouter, …')->required(),
            'action' => $schema->string()->description('set_key | test | sync_models')->required(),
            'api_key' => $schema->string()->description('The API key. Required for set_key; never returned by any tool.'),
            'url' => $schema->string()->description('Optional custom base URL for self-hosted or proxied providers.'),
        ];
    }
}
