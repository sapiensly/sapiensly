<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\User;
use App\Services\Apps\AppAccessContext;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectWriter;
use App\Services\Workflows\WorkflowEngine;
use RuntimeException;

/**
 * Executes one manifest action_sequence server action (create_record /
 * update_record / delete_record / run_workflow), routing record writes to the
 * internal store (RecordWriteService) or an external system for a connected
 * object (ConnectedObjectWriter). Extracted from AppActionController so the SAME
 * write path serves both surfaces that drive it: the runtime UI (the user clicked
 * save) and the runtime agent's approved proposals (power #3's gate) — the parity
 * invariant, anything the UI can do the agent can do, both through one executor.
 */
class AppActionExecutor
{
    public function __construct(
        private RecordWriteService $writes,
        private WorkflowEngine $workflows,
        private ExpressionResolver $expressions,
        private ConnectedObjectWriter $connectedWrites,
        private ConnectedIntegrationResolver $integrations,
        private RecordQueryService $records,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(App $app, array $manifest, array $action, array $context, ?User $user): array
    {
        $resolvedValues = $this->resolveValues($action['values'] ?? [], $context);
        $access = $context['__access'] ?? null;

        if (in_array($action['type'], ['create_record', 'update_record', 'delete_record'], true)) {
            $objectId = $action['object_id'];
            $this->authorize($access, $objectId, $action['type'], $resolvedValues);

            $object = $this->findObject($manifest, $objectId);
            if (($object['source']['type'] ?? 'internal') === 'connected') {
                return $this->executeConnectedAction($app, $object, $action, $context, $resolvedValues);
            }
        }

        if ($action['type'] === 'create_record') {
            $record = $this->writes->create($app, $manifest, $action['object_id'], $resolvedValues, $user);

            return ['record_id' => $record->id, 'data' => $record->data];
        }

        if ($action['type'] === 'update_record') {
            // Re-fetch through the row_filter-aware finder: a record outside the
            // user's role-scoped rows resolves to null (not-found), closing the
            // update privilege-escalation hole.
            $recordId = (string) $this->expressions->resolve($action['record_id_expression'], $context);
            $record = $this->records->find($app, $action['object_id'], $recordId, $manifest, $context);
            if ($record === null) {
                throw new RuntimeException("Record '{$recordId}' not found.");
            }
            $updated = $this->writes->update($app, $manifest, $record, $resolvedValues, $user);

            return ['record_id' => $updated->id, 'data' => $updated->data];
        }

        if ($action['type'] === 'delete_record') {
            $recordId = (string) $this->expressions->resolve($action['record_id_expression'], $context);
            $record = $this->records->find($app, $action['object_id'], $recordId, $manifest, $context);
            if ($record === null) {
                throw new RuntimeException("Record '{$recordId}' not found.");
            }
            $this->writes->delete($record, $app, $manifest, $user);

            return ['record_id' => $recordId];
        }

        if ($action['type'] === 'run_workflow') {
            $workflow = $this->findWorkflow($manifest, $action['workflow_id']);
            $inputResolved = $this->resolveValues($action['input'] ?? [], $context);
            $run = $this->workflows->run($app, $manifest, $workflow, 'manual', $inputResolved, $user);

            if ($run->status === 'failed') {
                throw new RuntimeException($run->error ?: 'Workflow run failed.');
            }

            return ['run_id' => $run->id, 'status' => $run->status];
        }

        throw new \LogicException("AppActionExecutor called with non-server type '{$action['type']}'.");
    }

    /**
     * Gate a record write against the user's AppAccessContext (null ⇒ no policy
     * layer, allowed). Enforces the object's CRUD action grant and rejects writes
     * that target a read-only field. Same gate for the UI and the runtime agent.
     *
     * @param  array<string, mixed>  $resolvedValues
     */
    private function authorize(mixed $access, string $objectId, string $actionType, array $resolvedValues): void
    {
        if (! $access instanceof AppAccessContext) {
            return;
        }

        $crud = match ($actionType) {
            'create_record' => 'create',
            'update_record' => 'update',
            'delete_record' => 'delete',
        };

        if (! $access->can($objectId, $crud)) {
            throw new RuntimeException("You are not allowed to {$crud} records of this type.");
        }

        if ($crud === 'delete') {
            return;
        }

        $readonly = array_values(array_intersect(array_keys($resolvedValues), $access->readonlyFieldSlugs($objectId)));
        if ($readonly !== []) {
            throw new RuntimeException('These fields are read-only: '.implode(', ', $readonly).'.');
        }
    }

    /**
     * Run a record CRUD action against a connected object's external system
     * (power #2 write path). Delete is unsupported (no delete operation in the
     * source schema). Failures are raised so the caller surfaces them.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $resolvedValues
     * @return array<string, mixed>
     */
    private function executeConnectedAction(App $app, array $object, array $action, array $context, array $resolvedValues): array
    {
        $integration = $this->integrations->resolve($app, $object['source']['integration_id'] ?? null);
        if ($integration === null) {
            throw new RuntimeException('This connected object needs an authorized connection.');
        }

        if ($action['type'] === 'create_record') {
            $result = $this->connectedWrites->create($object, $integration, $resolvedValues);
        } elseif ($action['type'] === 'update_record') {
            $externalId = (string) $this->expressions->resolve($action['record_id_expression'], $context);
            $result = $this->connectedWrites->update($object, $integration, $externalId, $resolvedValues);
        } else {
            throw new RuntimeException('Deleting connected records is not supported.');
        }

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException($result['error'] ?? 'The connected system rejected the write.');
        }

        return ['record_id' => $result['id'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function findObject(array $manifest, string $objectId): array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) === $objectId) {
                return $object;
            }
        }
        throw new RuntimeException("Object '{$objectId}' not found in manifest.");
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function findWorkflow(array $manifest, string $workflowId): array
    {
        foreach ($manifest['workflows'] ?? [] as $wf) {
            if ($wf['id'] === $workflowId) {
                return $wf;
            }
        }
        throw new RuntimeException("Workflow '{$workflowId}' not found in the App manifest.");
    }

    /**
     * Each entry in `values` is either a literal or a value_expression string
     * like "{{form.nombre}}". Resolve every value through ExpressionResolver
     * (a plain literal passes through unchanged).
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveValues(array $values, array $context): array
    {
        $resolved = [];
        foreach ($values as $slug => $value) {
            $resolved[$slug] = is_string($value)
                ? $this->expressions->resolve($value, $context)
                : $value;
        }

        return $resolved;
    }
}
