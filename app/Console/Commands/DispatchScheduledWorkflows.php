<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledWorkflowJob;
use App\Models\App;
use App\Services\Manifest\AppManifestService;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Fires every `schedule`-triggered workflow whose cron expression is due in the
 * current minute (FR-4.2). Registered on Laravel's scheduler at everyMinute().
 *
 * Idempotent per fire: a short-lived cache lock keyed by (workflow, minute)
 * means a double-run of the command — or an overlap — never dispatches the same
 * fire twice. Each due workflow becomes one queued RunScheduledWorkflowJob so a
 * slow or failing workflow can never block the dispatch sweep.
 */
class DispatchScheduledWorkflows extends Command
{
    protected $signature = 'flows:dispatch-scheduled';

    protected $description = 'Dispatch schedule-triggered workflows whose cron is due this minute';

    public function handle(AppManifestService $manifests): int
    {
        $now = Carbon::now('UTC');
        $minuteKey = $now->format('YmdHi');
        $dispatched = 0;

        App::query()->whereNotNull('current_version_id')->cursor()->each(
            function (App $app) use ($manifests, $now, $minuteKey, &$dispatched): void {
                $manifest = $manifests->getActiveManifest($app);
                if (! is_array($manifest)) {
                    return;
                }

                foreach ($manifest['workflows'] ?? [] as $workflow) {
                    if (($workflow['enabled'] ?? true) === false) {
                        continue;
                    }

                    $trigger = $workflow['trigger'] ?? [];
                    if (($trigger['type'] ?? null) !== 'schedule') {
                        continue;
                    }

                    $cron = (string) ($trigger['cron'] ?? '');
                    if (! CronExpression::isValidExpression($cron)) {
                        continue;
                    }

                    $tz = (string) ($trigger['timezone'] ?? 'UTC');
                    if (! (new CronExpression($cron))->isDue($now->copy()->setTimezone($tz))) {
                        continue;
                    }

                    // One fire per workflow per minute, even if the sweep overlaps.
                    $lock = "flows:scheduled:{$workflow['id']}:{$minuteKey}";
                    if (! Cache::add($lock, true, 120)) {
                        continue;
                    }

                    RunScheduledWorkflowJob::dispatch(
                        $app->id,
                        $workflow['id'],
                        $app->organization_id,
                        $app->user_id,
                        $now->toIso8601String(),
                    );
                    $dispatched++;
                }
            }
        );

        $this->info("Dispatched {$dispatched} scheduled workflow(s).");

        return self::SUCCESS;
    }
}
