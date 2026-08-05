<?php

namespace App\Console\Commands;

use App\Services\AiProviderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the shared model catalog from every connected provider's own
 * `/models` endpoint.
 *
 * The bootstrap lists in {@see AiProviderService::MODEL_CATALOGS} are a seed,
 * not the truth: a provider retiring an id makes them wrong, and the picker
 * then offers models the API no longer serves. Until now the only cures were
 * saving a key again or an admin pressing Sync per driver in the UI — so an
 * install nobody touches drifts silently, and the drift only surfaces as an
 * inference failure. This runs the same fetch-and-merge over every syncable
 * driver that holds a key, so the catalog is repairable without a person.
 *
 * Merge semantics come from syncDirectCatalogModels: new models arrive
 * DISABLED (an admin still opts each one in), labels refresh, and nothing is
 * deleted — so a default pointing at a retired id is never orphaned by a sync.
 */
#[Signature('ai:sync-models {--driver=* : Limit the refresh to these drivers}')]
#[Description("Refresh each connected AI provider's model catalog from its API.")]
class SyncAiCatalogCommand extends Command
{
    public function handle(AiProviderService $providers): int
    {
        $requested = array_filter((array) $this->option('driver'));
        $unknown = array_diff($requested, AiProviderService::SYNCABLE_DRIVERS);

        if ($unknown !== []) {
            $this->error('Not syncable: '.implode(', ', $unknown).'. Syncable drivers: '
                .implode(', ', AiProviderService::SYNCABLE_DRIVERS));

            return self::FAILURE;
        }

        $drivers = $requested !== [] ? $requested : AiProviderService::SYNCABLE_DRIVERS;
        $rows = [];
        $failed = false;

        foreach ($drivers as $driver) {
            $credentials = $providers->resolveGlobalCredentials($driver);

            if (empty($credentials['api_key'] ?? null)) {
                $rows[] = [$driver, 'no key', '—', '—'];

                continue;
            }

            // One provider being down must not stop the rest: the sweep is the
            // only unattended path to a fresh catalog, so it reports and moves on.
            try {
                $models = $providers->fetchProviderModels($driver, $credentials);
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('ai.catalog_sync_failed', [
                    'driver' => $driver,
                    'reason' => $e->getMessage(),
                ]);
                $rows[] = [$driver, 'error', '—', '—'];
                $failed = true;

                continue;
            }

            if ($models === []) {
                // fetchProviderModels swallows transport failures into an empty
                // list, so this is "unreachable or rejected the key" as much as
                // it is "returned nothing" — either way the catalog is unchanged.
                $rows[] = [$driver, 'no models', '0', '0'];
                $failed = true;

                continue;
            }

            $created = $providers->syncDirectCatalogModels($driver, $models);

            Log::channel('daily')->info('ai.models_synced', [
                'driver' => $driver,
                'fetched' => count($models),
                'created' => $created,
                'trigger' => 'command',
            ]);

            $rows[] = [$driver, 'ok', (string) count($models), (string) $created];
        }

        $this->table(['Driver', 'Status', 'Fetched', 'New'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
