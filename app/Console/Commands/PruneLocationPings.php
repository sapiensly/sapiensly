<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\LocationPing;
use App\Models\TrackingSession;
use App\Services\Manifest\AppManifestService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tracking\TrackingPolicy;
use Illuminate\Console\Command;

/**
 * A trail must not outlive the work it documents.
 *
 * The retention promise in `settings.tracking.retain_days` is only a promise if
 * something enforces it, and nothing else here deletes on a clock. Runs per
 * APP, because the span is the app owner's to choose: thirty days for a service
 * route, a day for something that only ever answers "did they arrive".
 *
 * Also closes sessions that stopped reporting. A phone that ran out of battery
 * mid-visit leaves one open for ever, and "who is being tracked right now" has
 * to be answerable.
 */
class PruneLocationPings extends Command
{
    protected $signature = 'tracking:prune {--hours=6 : Close sessions silent for this long}';

    protected $description = 'Delete location trails past each app\'s retention, and close abandoned sessions';

    public function handle(AppManifestService $manifests): int
    {
        $pings = 0;
        $closed = 0;

        App::query()->chunkById(50, function ($apps) use ($manifests, &$pings, &$closed) {
            foreach ($apps as $app) {
                // Each app's rows live under its owner's tenant scope, so the
                // scope is set before touching them — the same rule every
                // queued job here follows. Without it RLS answers with nothing
                // and the pruner silently deletes none of it.
                app(TenantContext::class)->set($app->organization_id, $app->user_id);

                try {
                    $manifest = $manifests->getActiveManifest($app);
                    if ($manifest === null) {
                        continue;
                    }

                    $policy = TrackingPolicy::fromManifest($manifest);
                    $cutoff = now()->subDays($policy->retainDays);

                    $pings += LocationPing::query()
                        ->where('app_id', $app->id)
                        ->where('recorded_at', '<', $cutoff)
                        ->delete();

                    // A session with no pings left is a row saying somebody was
                    // followed, with nothing to show for it.
                    TrackingSession::query()
                        ->where('app_id', $app->id)
                        ->whereNotNull('ended_at')
                        ->where('ended_at', '<', $cutoff)
                        ->delete();

                    $closed += TrackingSession::query()
                        ->where('app_id', $app->id)
                        ->whereNull('ended_at')
                        ->where(function ($q) {
                            $q->where('last_ping_at', '<', now()->subHours((int) $this->option('hours')))
                                ->orWhere(function ($q) {
                                    $q->whereNull('last_ping_at')
                                        ->where('started_at', '<', now()->subHours((int) $this->option('hours')));
                                });
                        })
                        ->update(['ended_at' => now()]);
                } finally {
                    // Never left set: the next app in the chunk belongs to
                    // somebody else, and a leaked scope is how one tenant's
                    // pruning reads another tenant's rows.
                    app(TenantContext::class)->forget();
                }
            }
        });

        $this->info("Deleted {$pings} location points and closed {$closed} abandoned sessions.");

        return self::SUCCESS;
    }
}
