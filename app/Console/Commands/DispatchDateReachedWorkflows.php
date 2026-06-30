<?php

namespace App\Console\Commands;

use App\Jobs\RunDateReachedWorkflowJob;
use App\Models\App;
use App\Models\Record;
use App\Models\WorkflowSweepState;
use App\Services\Manifest\AppManifestService;
use App\Services\Workflows\DateReachedEvaluator;
use App\Services\Workflows\TriggerFilterMatcher;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fires `record.date_reached` workflows: a record's date field, shifted by the
 * trigger's offset, reaching "now". Registered on the scheduler at everyMinute,
 * withoutOverlapping (so the per-workflow cursor advances without races).
 *
 * Exactly-once without a per-record marker: each workflow keeps a monotonic
 * `last_swept_at` cursor, so every target moment falls in exactly one contiguous
 * (last_swept_at, now] window. A new workflow's cursor is seeded at "now", so it
 * never backfires historical records. A coarse SQL window narrows candidates;
 * the exact instant (date fields anchor at `at`/timezone) is checked in memory.
 */
class DispatchDateReachedWorkflows extends Command
{
    protected $signature = 'flows:dispatch-date-reached';

    protected $description = 'Fire record.date_reached workflows for records whose date offset is due';

    /** Most candidate records to inspect per workflow per sweep. */
    private const CANDIDATE_CAP = 1000;

    public function __construct(
        private DateReachedEvaluator $evaluator,
        private TriggerFilterMatcher $matcher,
    ) {
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
                        && (($w['trigger'] ?? [])['type'] ?? null) === 'record.date_reached');

                if ($workflows->isEmpty()) {
                    return;
                }

                $tenant->set($app->organization_id, $app->user_id);
                try {
                    foreach ($workflows as $workflow) {
                        $dispatched += $this->sweepWorkflow($app, $manifest, $workflow, $now);
                    }
                } finally {
                    $tenant->forget();
                }
            }
        );

        $this->info("Dispatched {$dispatched} date-reached workflow run(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $workflow
     */
    private function sweepWorkflow(App $app, array $manifest, array $workflow, CarbonImmutable $now): int
    {
        $trigger = $workflow['trigger'] ?? [];
        $objectId = (string) ($trigger['object_id'] ?? '');
        $fieldId = (string) ($trigger['field_id'] ?? '');

        $object = $this->findObject($manifest, $objectId);
        $field = $this->findField($object, $fieldId);
        if ($object === null || $field === null) {
            return 0;
        }
        $fieldType = (string) ($field['type'] ?? '');
        if (! in_array($fieldType, ['date', 'datetime'], true)) {
            return 0;
        }

        // First sighting: seed the cursor at now and fire nothing (no backfill).
        $cursor = WorkflowSweepState::query()->where('workflow_id', $workflow['id'])->first();
        if ($cursor === null) {
            WorkflowSweepState::create([
                'app_id' => $app->id,
                'workflow_id' => $workflow['id'],
                'last_swept_at' => $now,
            ]);

            return 0;
        }

        $lastSwept = CarbonImmutable::parse($cursor->last_swept_at)->utc();
        $offset = is_array($trigger['offset'] ?? null) ? $trigger['offset'] : [];
        $at = (string) ($trigger['at'] ?? '09:00');
        $timezone = (string) ($trigger['timezone'] ?? 'UTC');
        $filter = is_array($trigger['filter'] ?? null) ? $trigger['filter'] : null;

        $candidates = $this->candidateRecords($app, $objectId, (string) $field['slug'], $offset, $lastSwept, $now);

        $dispatched = 0;
        foreach ($candidates as $record) {
            $raw = ($record->data ?? [])[$field['slug']] ?? null;
            $target = $this->evaluator->targetInstant($raw, $fieldType, $offset, $at, $timezone);
            if ($target === null) {
                continue;
            }
            // Fire only for target moments inside this contiguous window.
            if (! ($target->greaterThan($lastSwept) && $target->lessThanOrEqualTo($now))) {
                continue;
            }

            $payload = $this->recordPayload($record);
            if ($filter !== null && ! $this->matcher->matches($filter, $object, $payload)) {
                continue;
            }

            RunDateReachedWorkflowJob::dispatch(
                $app->id,
                $workflow['id'],
                $app->organization_id,
                $app->user_id,
                $payload,
                $target->toIso8601String(),
            );
            $dispatched++;
        }

        $cursor->update(['last_swept_at' => $now]);

        return $dispatched;
    }

    /**
     * Coarse SQL window: records whose date field could map to a target moment
     * in (lastSwept, now]. base = target - offset, so the base window is the
     * target window shifted by the offset; ±1 day of slack covers the at/timezone
     * shift on `date` fields. The exact instant is then re-checked in memory.
     *
     * @param  array<string, mixed>  $offset
     * @return Collection<int, Record>
     */
    private function candidateRecords(
        App $app,
        string $objectId,
        string $slug,
        array $offset,
        CarbonImmutable $lastSwept,
        CarbonImmutable $now,
    ): Collection {
        $offsetMinutes = $this->evaluator->offsetMinutes($offset);
        $from = $lastSwept->subMinutes($offsetMinutes)->subDay();
        $to = $now->subMinutes($offsetMinutes)->addDay();

        return Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $objectId)
            ->whereRaw('(data->>?) is not null', [$slug])
            ->whereRaw('(data->>?)::timestamptz >= ?', [$slug, $from->toDateTimeString()])
            ->whereRaw('(data->>?)::timestamptz <= ?', [$slug, $to->toDateTimeString()])
            ->limit(self::CANDIDATE_CAP)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayload(Record $record): array
    {
        return [
            'id' => $record->id,
            'app_id' => $record->app_id,
            'object_definition_id' => $record->object_definition_id,
            'data' => $record->data,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findObject(array $manifest, string $objectId): ?array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) === $objectId) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $object
     * @return array<string, mixed>|null
     */
    private function findField(?array $object, string $fieldId): ?array
    {
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['id'] ?? null) === $fieldId) {
                return $field;
            }
        }

        return null;
    }
}
