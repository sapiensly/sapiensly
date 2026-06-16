<?php

namespace App\Services\Ai;

use App\Models\AiCatalogModel;
use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for classifying an AI call: its driver (from the model
 * catalog) and whether it runs on the org's OWN provider key (`own`) or a
 * platform/system key (`system`). Shared by the usage recorder (attribution) and
 * the spend guard (enforcement) so they can never disagree about what counts as
 * "system" spend.
 */
class AiSourceResolver
{
    private const DRIVER_MAP_TTL = 300;

    public function __construct(private readonly AiProviderService $providers) {}

    public function driver(string $model): string
    {
        $map = Cache::remember('ai_model_driver_map', self::DRIVER_MAP_TTL, fn (): array => AiCatalogModel::query()
            ->get(['model_id', 'driver'])
            ->keyBy('model_id')
            ->map(fn (AiCatalogModel $m) => $m->driver)
            ->all());

        return $map[$model] ?? 'unknown';
    }

    /**
     * "own" when the user's context has its own active provider for the model's
     * driver (BYOK — they pay), else "system" (the platform key pays).
     */
    public function source(string $model, ?User $user): string
    {
        if ($user === null) {
            return 'system';
        }

        try {
            $ownDrivers = $this->providers->getProvidersForContext($user)->pluck('driver')->all();

            return in_array($this->driver($model), $ownDrivers, true) ? 'own' : 'system';
        } catch (\Throwable) {
            return 'system';
        }
    }
}
