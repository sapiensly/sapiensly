<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\Record;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectWriter;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\ExpressionResolver;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Endpoint that executes a manifest action_sequence sent by the runtime
 * client. Splits actions into:
 *   - server-side: create_record, update_record, delete_record — executed
 *     here via RecordWriteService, with values resolved through the
 *     ExpressionResolver against the page context.
 *   - client-side: navigate, open_modal, close_modal, show_toast, refresh —
 *     echoed back to the client in `client_actions` for it to apply.
 *
 * Response shape: { ok, errors?, results, client_actions }
 */
class AppActionController extends Controller
{
    private const SERVER_SIDE = ['create_record', 'update_record', 'delete_record', 'run_workflow'];

    private const CLIENT_SIDE = ['navigate', 'open_modal', 'close_modal', 'show_toast', 'refresh'];

    public function __construct(
        private AppManifestService $manifestService,
        private RecordWriteService $writes,
        private WorkflowEngine $workflows,
        private ExpressionResolver $expressions,
        private ConnectedObjectWriter $connectedWrites,
        private ConnectedIntegrationResolver $integrations,
    ) {}

    public function __invoke(Request $request, string $appSlug): JsonResponse
    {
        $user = $request->user();

        $app = App::query()
            ->forAccountContext($user)
            ->where('slug', $appSlug)
            ->first();

        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null) {
            throw new NotFoundHttpException("App '{$appSlug}' has no published manifest.");
        }

        $request->validate([
            'actions' => ['required', 'array'],
            'actions.*.type' => ['required', 'string'],
            'params' => ['nullable', 'array'],
            'form' => ['nullable', 'array'],
            // row: per-row context emitted by table action columns. Shape:
            // {id: rec_..., data: {<slug>: <value>}} — drives {{row.id}} /
            // {{row.data.<slug>}} expressions in the on_click action sequence.
            'row' => ['nullable', 'array'],
        ]);

        // Use input() so we keep nested fields the validator didn't enumerate
        // (object_id, values, record_id_expression — they vary per action).
        $actions = $request->input('actions', []);
        $context = [
            'current_user' => ['id' => $user->id, 'email' => $user->email],
            'params' => $request->input('params', []) ?? [],
            'form' => $request->input('form', []) ?? [],
            'row' => $request->input('row', []) ?? [],
        ];

        $results = [];
        $clientActions = [];
        $errors = [];
        $ok = true;

        foreach ($actions as $i => $action) {
            $type = $action['type'];

            if (in_array($type, self::CLIENT_SIDE, true)) {
                $clientActions[] = $action;
                $results[] = ['index' => $i, 'type' => $type, 'ok' => true];

                continue;
            }

            if (! in_array($type, self::SERVER_SIDE, true)) {
                $errors[$i] = ['type' => 'unknown_action', 'message' => "Unknown action type '{$type}'."];
                $ok = false;

                continue;
            }

            try {
                $result = $this->executeServerAction($app, $manifest, $action, $context, $user);
                $results[] = ['index' => $i, 'type' => $type, 'ok' => true] + $result;
            } catch (RecordValidationException $e) {
                $errors[$i] = ['type' => 'validation', 'fields' => $e->errors];
                $ok = false;
            } catch (\Throwable $e) {
                $errors[$i] = ['type' => 'server_error', 'message' => $e->getMessage()];
                $ok = false;
            }
        }

        return response()->json([
            'ok' => $ok,
            'results' => $results,
            'client_actions' => $clientActions,
            'errors' => $errors,
        ], $ok ? 200 : 422);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function executeServerAction(App $app, array $manifest, array $action, array $context, $user): array
    {
        $resolvedValues = $this->resolveValues($action['values'] ?? [], $context);

        if (in_array($action['type'], ['create_record', 'update_record', 'delete_record'], true)) {
            $object = $this->findObject($manifest, $action['object_id']);
            if (($object['source']['type'] ?? 'internal') === 'connected') {
                return $this->executeConnectedAction($app, $object, $action, $context, $resolvedValues);
            }
        }

        if ($action['type'] === 'create_record') {
            $record = $this->writes->create($app, $manifest, $action['object_id'], $resolvedValues, $user);

            return ['record_id' => $record->id];
        }

        if ($action['type'] === 'update_record') {
            $recordId = (string) $this->expressions->resolve($action['record_id_expression'], $context);
            $record = Record::query()
                ->where('app_id', $app->id)
                ->where('object_definition_id', $action['object_id'])
                ->find($recordId);
            if ($record === null) {
                throw new \RuntimeException("Record '{$recordId}' not found.");
            }
            $updated = $this->writes->update($app, $manifest, $record, $resolvedValues, $user);

            return ['record_id' => $updated->id];
        }

        if ($action['type'] === 'delete_record') {
            $recordId = (string) $this->expressions->resolve($action['record_id_expression'], $context);
            $record = Record::query()
                ->where('app_id', $app->id)
                ->where('object_definition_id', $action['object_id'])
                ->find($recordId);
            if ($record === null) {
                throw new \RuntimeException("Record '{$recordId}' not found.");
            }
            $this->writes->delete($record, $app, $manifest, $user);

            return ['record_id' => $recordId];
        }

        if ($action['type'] === 'run_workflow') {
            $workflow = $this->findWorkflow($manifest, $action['workflow_id']);
            $inputResolved = $this->resolveValues($action['input'] ?? [], $context);
            $run = $this->workflows->run($app, $manifest, $workflow, 'manual', $inputResolved, $user);

            // WorkflowEngine swallows step failures (it marks the run 'failed'
            // and returns rather than throwing) so a single bad step doesn't
            // 500 the whole request. Surface that failure to the caller here so
            // it lands in `errors` and the client can show it instead of
            // silently firing the sequence's success toast.
            if ($run->status === 'failed') {
                throw new \RuntimeException($run->error ?: 'Workflow run failed.');
            }

            return ['run_id' => $run->id, 'status' => $run->status];
        }

        throw new \LogicException("executeServerAction called with non-server type '{$action['type']}'.");
    }

    /**
     * Run a record CRUD action against a connected object's external system
     * (builder power #2 write path). The logged-in user is the actor, so this is
     * a direct write. Create/update map to the source's operations; delete is not
     * supported for connected objects (the source schema has no delete operation).
     * A failure is raised so it lands in the response `errors`, never a false success.
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
            throw new \RuntimeException('This connected object needs an authorized connection.');
        }

        if ($action['type'] === 'create_record') {
            $result = $this->connectedWrites->create($object, $integration, $resolvedValues);
        } elseif ($action['type'] === 'update_record') {
            $externalId = (string) $this->expressions->resolve($action['record_id_expression'], $context);
            $result = $this->connectedWrites->update($object, $integration, $externalId, $resolvedValues);
        } else {
            throw new \RuntimeException('Deleting connected records is not supported.');
        }

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException($result['error'] ?? 'The connected system rejected the write.');
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
        throw new \RuntimeException("Object '{$objectId}' not found in manifest.");
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
        throw new \RuntimeException("Workflow '{$workflowId}' not found in the App manifest.");
    }

    /**
     * Each entry in `values` is either a literal or a value_expression string
     * like "{{form.nombre}}". Resolve every value through ExpressionResolver
     * before handing the map to RecordWriteService.
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
