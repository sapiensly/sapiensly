<?php

namespace App\Services\Workflows;

use App\Models\App;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Looks up workflows in the App's manifest whose trigger matches a runtime
 * event (record.created/updated/deleted) and runs each one inline via the
 * WorkflowEngine. Failures are caught and logged — they MUST NOT propagate
 * up the record write path, or a buggy workflow would block CRUD.
 */
class WorkflowTriggerDispatcher
{
    public function __construct(
        private WorkflowEngine $engine,
        private TriggerFilterMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $payload  trigger payload (e.g. {record: {...}})
     */
    public function dispatch(
        App $app,
        array $manifest,
        string $eventType,
        array $payload,
        ?User $user = null,
    ): void {
        foreach ($manifest['workflows'] ?? [] as $workflow) {
            if (! ($workflow['enabled'] ?? true)) {
                continue;
            }

            $trigger = $workflow['trigger'] ?? [];
            if (($trigger['type'] ?? null) !== $eventType) {
                continue;
            }

            $expectedObjectId = $trigger['object_id'] ?? null;
            $actualObjectId = $payload['record']['object_definition_id'] ?? null;
            if ($expectedObjectId !== null && $actualObjectId !== $expectedObjectId) {
                continue;
            }

            try {
                // Only fire when the trigger's filter matches the written record.
                // Evaluated inside the try so a malformed filter is logged and the
                // workflow skipped — never blocking the record write.
                $filter = $trigger['filter'] ?? null;
                if (is_array($filter) && $filter !== []) {
                    $object = $this->findObject($manifest, (string) $expectedObjectId);
                    if ($object === null
                        || ! $this->matcher->matches($filter, $object, $payload['record'] ?? [])) {
                        continue;
                    }
                }

                $this->engine->run($app, $manifest, $workflow, $eventType, $payload, $user);
            } catch (\Throwable $e) {
                Log::warning('Workflow trigger failed', [
                    'workflow_id' => $workflow['id'],
                    'event' => $eventType,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
}
