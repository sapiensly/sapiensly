<?php

namespace App\Console\Commands;

use App\Jobs\RunIntegrationPollWorkflowJob;
use App\Models\App;
use App\Models\Tool;
use App\Models\WorkflowSweepState;
use App\Services\Manifest\AppManifestService;
use App\Services\ToolExecutionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Drives `integration.poll` workflows: every `interval_minutes`, run a connected
 * tool that returns a list, then fire the workflow once per item newer than the
 * stored watermark. Registered everyMinute, withoutOverlapping.
 *
 * Pull counterpart to the push webhooks, for providers without webhooks.
 * Exactly-once-ish without a per-item marker: a monotonic watermark (the max
 * value at `watermark_path`) is kept per workflow in workflow_sweep_states; the
 * first poll seeds the watermark and fires nothing (no backfill). `last_swept_at`
 * gates the polling cadence and advances even on errors so a broken poll can't
 * hammer the provider.
 */
class DispatchPollWorkflows extends Command
{
    protected $signature = 'flows:dispatch-polls';

    protected $description = 'Poll integration.poll workflows and fire on newly-seen items';

    private const DEFAULT_INTERVAL = 15;

    /** Cap on items fired from one poll, so a huge first/late response can't storm the queue. */
    private const MAX_ITEMS = 200;

    public function __construct(private ToolExecutionService $toolExecution)
    {
        parent::__construct();
    }

    public function handle(AppManifestService $manifests, TenantContext $tenant): int
    {
        $now = CarbonImmutable::now('UTC');
        $dispatched = 0;

        App::query()->whereNotNull('current_version_id')->cursor()->each(
            function (App $app) use ($manifests, $tenant, $now, &$dispatched): void {
                $manifest = $manifests->getActiveManifest($app);
                if (! is_array($manifest)) {
                    return;
                }

                $workflows = collect($manifest['workflows'] ?? [])
                    ->filter(fn ($w): bool => is_array($w)
                        && ($w['enabled'] ?? true) !== false
                        && (($w['trigger'] ?? [])['type'] ?? null) === 'integration.poll');

                if ($workflows->isEmpty()) {
                    return;
                }

                $tenant->set($app->organization_id, $app->user_id);
                try {
                    foreach ($workflows as $workflow) {
                        $dispatched += $this->pollWorkflow($app, $workflow, $now);
                    }
                } finally {
                    $tenant->forget();
                }
            }
        );

        $this->info("Dispatched {$dispatched} poll workflow run(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function pollWorkflow(App $app, array $workflow, CarbonImmutable $now): int
    {
        $trigger = $workflow['trigger'] ?? [];
        $toolId = (string) ($trigger['tool_id'] ?? '');
        $watermarkField = (string) ($trigger['watermark_path'] ?? '');
        if ($toolId === '' || $watermarkField === '') {
            return 0;
        }
        $itemsPath = (string) ($trigger['items_path'] ?? '');
        $interval = max(1, (int) ($trigger['interval_minutes'] ?? self::DEFAULT_INTERVAL));

        $cursor = WorkflowSweepState::query()->where('workflow_id', $workflow['id'])->first();
        $firstPoll = $cursor === null;

        // Cadence gate: skip until the interval has elapsed (first poll runs now).
        if (! $firstPoll) {
            $lastSwept = CarbonImmutable::parse($cursor->last_swept_at)->utc();
            if ($lastSwept->addMinutes($interval)->greaterThan($now)) {
                return 0;
            }
        }

        $tool = Tool::query()
            ->where('organization_id', $app->organization_id)
            ->where('status', 'active')
            ->whereKey($toolId)
            ->first();
        if (! $tool instanceof Tool) {
            // Seed the cursor so we don't re-resolve a missing tool every minute.
            $this->advanceCursor($app, (string) $workflow['id'], $now, $cursor?->watermark);

            return 0;
        }

        $result = $this->toolExecution->execute($tool, []);
        if (! $result->success) {
            $this->advanceCursor($app, (string) $workflow['id'], $now, $cursor?->watermark);

            return 0;
        }

        $items = $this->extractItems($result->data, $itemsPath);
        $storedWatermark = $cursor?->watermark;

        $dispatched = 0;
        $newWatermark = $storedWatermark;
        $count = 0;
        foreach ($items as $item) {
            if ($count >= self::MAX_ITEMS) {
                break;
            }
            $count++;

            $watermark = data_get($item, $watermarkField);
            if (! is_scalar($watermark) || (string) $watermark === '') {
                continue;
            }
            $watermark = (string) $watermark;
            $newWatermark = $newWatermark === null ? $watermark : $this->maxWatermark($newWatermark, $watermark);

            // First poll only seeds the watermark — never backfires history.
            if (! $firstPoll && $storedWatermark !== null && $this->isNewer($watermark, $storedWatermark)) {
                RunIntegrationPollWorkflowJob::dispatch(
                    $app->id,
                    $workflow['id'],
                    $app->organization_id,
                    $app->user_id,
                    ['tool_id' => $toolId, 'item' => $item, 'watermark' => $watermark],
                );
                $dispatched++;
            }
        }

        $this->advanceCursor($app, (string) $workflow['id'], $now, $newWatermark);

        return $dispatched;
    }

    /**
     * @return list<mixed>
     */
    private function extractItems(mixed $data, string $itemsPath): array
    {
        $items = $itemsPath === '' ? $data : data_get($data, $itemsPath);

        return is_array($items) ? array_values($items) : [];
    }

    private function advanceCursor(App $app, string $workflowId, CarbonImmutable $now, ?string $watermark): void
    {
        WorkflowSweepState::query()->updateOrCreate(
            ['workflow_id' => $workflowId],
            ['app_id' => $app->id, 'last_swept_at' => $now, 'watermark' => $watermark],
        );
    }

    /** Is $a strictly newer than $b? Numeric when both look numeric, else lexical. */
    private function isNewer(string $a, string $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a > (float) $b;
        }

        return strcmp($a, $b) > 0;
    }

    private function maxWatermark(string $a, string $b): string
    {
        return $this->isNewer($a, $b) ? $a : $b;
    }
}
