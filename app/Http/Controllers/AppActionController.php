<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\BlockVisibilityFilter;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\AppActionExecutor;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\ExpressionResolver;
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
        private ExpressionResolver $expressions,
        private BlockDataResolver $blockData,
        private BlockVisibilityFilter $visibility,
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
            // The page the action was fired from — lets us return fresh block
            // data for a `refresh` in the SAME response (no second round-trip).
            'page' => ['nullable', 'string'],
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
                // Resolve the client action's expression-bearing fields server-side
                // so a navigate/toast/open_modal can reference {{record.id}} (the id
                // a prior create/update minted — the client never sees it) as well
                // as {{params.*}} / {{row.*}}. They reach the client already concrete.
                $clientActions[] = $this->resolveClientAction($action, $context);
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
                // Expose the record a create/update just produced to the rest of the
                // sequence as {{record.id}} / {{record.data.<slug>}}: a later action
                // (a child create, or a navigate to the new record's detail page)
                // can bind to it. This is the "current record" handoff.
                if (isset($result['record_id']) && $result['record_id'] !== null) {
                    $context['record'] = ['id' => $result['record_id'], 'data' => $result['data'] ?? []];
                }
            } catch (RecordValidationException $e) {
                $errors[$i] = ['type' => 'validation', 'fields' => $e->errors];
                $ok = false;
            } catch (\Throwable $e) {
                $errors[$i] = ['type' => 'server_error', 'message' => $e->getMessage()];
                $ok = false;
            }
        }

        // Single round-trip refresh: when the sequence asks to refresh and we know
        // the page, resolve its (visible) blocks' data NOW and ship it back, so the
        // client patches in place instead of firing a second full page reload.
        $blockData = null;
        $wantsRefresh = collect($clientActions)->contains(fn ($a) => ($a['type'] ?? null) === 'refresh');
        if ($ok && $wantsRefresh) {
            $page = $this->findPage($manifest, $request->input('page'));
            if ($page !== null) {
                $blocks = $this->visibility->visibleBlocks($page['blocks'] ?? [], $access, $context);
                $blockData = $this->blockData->resolve($app, $blocks, $manifest, $context);
            }
        }

        $payload = [
            'ok' => $ok,
            'results' => $results,
            'client_actions' => $clientActions,
            'errors' => $errors,
        ];
        if ($blockData !== null) {
            $payload['block_data'] = $blockData;
        }

        return response()->json($payload, $ok ? 200 : 422);
    }

    /**
     * Resolve the page the action came from (by slug; the first page when no slug
     * was sent), or null if it can't be found.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findPage(array $manifest, ?string $slug): ?array
    {
        $pages = $manifest['pages'] ?? [];
        if ($pages === []) {
            return null;
        }
        if ($slug === null || $slug === '') {
            return $pages[0];
        }
        foreach ($pages as $page) {
            if (($page['slug'] ?? null) === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Resolve the expression-bearing string fields of a client action against the
     * running context (navigate `to`, show_toast `message`, open_modal `params`)
     * so tokens like {{record.id}} / {{params.x}} / {{row.id}} arrive concrete.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveClientAction(array $action, array $context): array
    {
        foreach (['to', 'message'] as $key) {
            if (isset($action[$key]) && is_string($action[$key])) {
                $action[$key] = $this->expressions->resolve($action[$key], $context);
            }
        }

        if (isset($action['params']) && is_array($action['params'])) {
            foreach ($action['params'] as $key => $value) {
                if (is_string($value)) {
                    $action['params'][$key] = $this->expressions->resolve($value, $context);
                }
            }
        }

        return $action;
    }
}
