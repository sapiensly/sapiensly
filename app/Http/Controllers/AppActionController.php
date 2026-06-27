<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\AppActionExecutor;
use App\Services\Records\RecordValidationException;
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
        private AppActionExecutor $executor,
        private AppAccessResolver $accessResolver,
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

        // Resolve the user's app-role capabilities once and carry them in the
        // context under __access; AppActionExecutor and RecordQueryService read
        // it to gate CRUD, strip read-only writes, and re-check row_filter.
        $access = $this->accessResolver->resolve($app, $manifest, $user);
        if (! $access->hasAccess) {
            abort(403, 'You do not have access to this app.');
        }

        // Use input() so we keep nested fields the validator didn't enumerate
        // (object_id, values, record_id_expression — they vary per action).
        $actions = $request->input('actions', []);
        $context = [
            'current_user' => ['id' => $user->id, 'email' => $user->email],
            'params' => $request->input('params', []) ?? [],
            'form' => $request->input('form', []) ?? [],
            'row' => $request->input('row', []) ?? [],
            '__access' => $access,
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
                $result = $this->executor->execute($app, $manifest, $action, $context, $user);
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
}
