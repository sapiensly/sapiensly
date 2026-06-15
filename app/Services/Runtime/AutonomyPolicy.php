<?php

namespace App\Services\Runtime;

/**
 * Decides whether a runtime-agent proposed write may auto-execute WITHOUT human
 * approval (builder power #3 autonomy engine). This is the highest-blast-radius
 * decision in the system, so it is conservative and fail-closed — it returns
 * true ONLY for an explicitly safe-marked capability, and bakes in hard limits
 * that no manifest can override:
 *
 *   - the agent's master switch must be autonomy="safe";
 *   - only create/update auto-execute — delete and run_workflow NEVER do;
 *   - only INTERNAL objects auto-execute — connected (external system) writes
 *     are always gated;
 *   - the (object, action) pair must be listed in manifest.agent.safe.
 *
 * Anything not matching all of the above stays gated (propose-don't-mutate).
 */
class AutonomyPolicy
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $action  an AppActionExecutor-shaped action
     */
    public function isAutoExecutable(array $manifest, array $action): bool
    {
        $agent = $manifest['agent'] ?? [];
        if (! is_array($agent) || ($agent['autonomy'] ?? 'propose') !== 'safe') {
            return false;
        }

        // delete and run_workflow are never auto-executable (hard rule).
        $short = match ($action['type'] ?? '') {
            'create_record' => 'create',
            'update_record' => 'update',
            default => null,
        };
        if ($short === null) {
            return false;
        }

        $objectId = $action['object_id'] ?? null;
        $object = $this->findObject($manifest, $objectId);
        if ($object === null) {
            return false;
        }

        // Connected (external system) writes are always gated.
        if (($object['source']['type'] ?? 'internal') === 'connected') {
            return false;
        }

        foreach ($agent['safe'] ?? [] as $entry) {
            if (is_array($entry)
                && ($entry['object_id'] ?? null) === $objectId
                && in_array($short, $entry['actions'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findObject(array $manifest, ?string $objectId): ?array
    {
        if ($objectId === null) {
            return null;
        }
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) === $objectId) {
                return $object;
            }
        }

        return null;
    }
}
