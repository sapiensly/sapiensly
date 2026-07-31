<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Services\AiProviderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('The AI providers this platform can call: every known driver, whether it is connected, WHERE its key comes from (a saved global row in the database, or the .env config), a masked fingerprint of that key, when it was last rotated, how many of its catalog models are enabled, and whether its model list can be synced live. Keys are never returned in full. Read-only — connect or rotate with manage_provider_key.')]
class ListPlatformProvidersTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $service = app(AiProviderService::class);

        $saved = AiProvider::query()
            ->where('visibility', 'global')
            ->get()
            ->mapWithKeys(function (AiProvider $provider) {
                $credentials = $provider->credentials ?? [];

                return [$provider->driver => [
                    'masked' => $this->mask((string) ($credentials['api_key'] ?? '')),
                    'rotated_at' => $credentials['rotated_at'] ?? null,
                ]];
            });

        $enabledCounts = AiCatalogModel::query()
            ->where('is_enabled', true)
            ->selectRaw('driver, count(*) as aggregate')
            ->groupBy('driver')
            ->pluck('aggregate', 'driver');

        $totalCounts = AiCatalogModel::query()
            ->selectRaw('driver, count(*) as aggregate')
            ->groupBy('driver')
            ->pluck('aggregate', 'driver');

        $providers = [];
        foreach (AiProviderService::DRIVER_LABELS as $driver => $label) {
            $source = $service->driverConfiguredSource($driver);
            $envKey = (string) config("ai.providers.{$driver}.key", '');

            $providers[] = [
                'driver' => $driver,
                'label' => $label,
                'kind' => $service->isBroker($driver) ? 'broker' : 'direct',
                'connected' => $source !== null,
                'key_source' => $source,
                'key_masked' => $saved[$driver]['masked'] ?? ($envKey !== '' ? $this->mask($envKey) : null),
                'last_rotated_at' => $saved[$driver]['rotated_at'] ?? null,
                'credential_fields' => AiProviderService::DRIVER_CREDENTIAL_FIELDS[$driver] ?? ['api_key'],
                'syncable' => $service->isSyncable($driver),
                'models_enabled' => (int) ($enabledCounts[$driver] ?? 0),
                'models_total' => (int) ($totalCounts[$driver] ?? 0),
            ];
        }

        return Response::json([
            'providers' => $providers,
            'connected' => count(array_filter($providers, fn (array $p) => $p['connected'])),
            'note' => 'key_source "db" is a saved global key and overrides "env" (the key in configuration). A provider with no key cannot have its models enabled.',
        ]);
    }

    private function mask(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        if (strlen($key) <= 10) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 6).'…'.substr($key, -4);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
