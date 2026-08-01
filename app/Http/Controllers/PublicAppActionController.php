<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessContext;
use App\Services\Apps\AppAccessResolver;
use App\Services\Apps\BlockVisibilityFilter;
use App\Services\Apps\PortalAuth;
use App\Services\Records\AppActionExecutor;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\ExpressionResolver;
use App\Services\Records\RecordValidationException;
use App\Support\Http\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Executes an action sequence fired by a visitor on a PUBLIC PORTAL — the
 * anonymous sibling of {@see AppActionController}. BindPublicAppContext has
 * already gated the portal and bound the owner's tenant scope.
 *
 * The write path is narrower than the authenticated one in four deliberate
 * ways, each closing a hole that only exists once the door is open to the
 * internet:
 *
 *  1. `permissions.public.allow_writes` is the outer gate. While it is false
 *     the portal is read-only whatever the object policies say, and every
 *     server-side action is refused before it is looked at.
 *  2. The strict access context gates each write per object. An object with no
 *     explicit create/update/delete grant for the visitor role is refused —
 *     silence in the manifest is never permission here.
 *  3. `run_workflow` is refused outright. A workflow can call connectors, spend
 *     model tokens and write anywhere; handing a stranger a button that runs
 *     one is an unbounded liability. Automation still reaches a portal
 *     submission the safe way — a `record.created` trigger on the object the
 *     visitor wrote fires exactly as it does for a landing lead.
 *  4. Honeypot + Turnstile + route throttle, the same floor the lead endpoint
 *     stands on.
 *
 * Response shape matches the authenticated endpoint so the runtime client needs
 * no branch: { ok, errors?, results, client_actions, block_data? }.
 */
class PublicAppActionController extends Controller
{
    private const SERVER_SIDE = ['create_record', 'update_record', 'delete_record'];

    private const CLIENT_SIDE = ['navigate', 'open_modal', 'close_modal', 'show_toast', 'refresh'];

    /** Action → the capability the visitor role must hold on the target object. */
    private const REQUIRED_CAPABILITY = [
        'create_record' => 'create',
        'update_record' => 'update',
        'delete_record' => 'delete',
    ];

    public function __construct(
        private readonly AppActionExecutor $executor,
        private readonly AppAccessResolver $accessResolver,
        private readonly ExpressionResolver $expressions,
        private readonly BlockDataResolver $blockData,
        private readonly BlockVisibilityFilter $visibility,
        private readonly PortalAuth $portalAuth,
    ) {}

    public function __invoke(Request $request, string $publicSlug): JsonResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicApp');
        /** @var array<string, mixed> $manifest */
        $manifest = $request->attributes->get('publicAppManifest');

        $request->validate([
            'actions' => ['required', 'array', 'max:20'],
            'actions.*.type' => ['required', 'string'],
            'params' => ['nullable', 'array'],
            'form' => ['nullable', 'array'],
            'row' => ['nullable', 'array'],
            'page' => ['nullable', 'string'],
            // The honeypot: humans never see it, bots love it.
            'website' => ['sometimes', 'nullable', 'string'],
            'turnstile_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $portalUser = $this->portalAuth->current($request, $app);

        $access = $this->accessResolver->resolvePublic($manifest, signedIn: $portalUser !== null);
        if (! $access->hasAccess) {
            abort(404);
        }

        $actions = $request->input('actions', []);
        $writesRequested = collect($actions)->contains(
            fn ($a) => ! in_array($a['type'] ?? '', self::CLIENT_SIDE, true),
        );

        if ($writesRequested) {
            $allowWrites = ($manifest['permissions']['public']['allow_writes'] ?? false) === true;
            if (! $allowWrites) {
                return new JsonResponse([
                    'ok' => false,
                    'results' => [],
                    'client_actions' => [],
                    'errors' => [0 => ['type' => 'read_only', 'message' => 'This page is read-only.']],
                ], 403);
            }

            // Honeypot tripped → report success, write nothing. Never tip a bot off.
            if (trim((string) $request->input('website', '')) !== '') {
                return new JsonResponse([
                    'ok' => true,
                    'results' => [],
                    'client_actions' => [],
                    'errors' => [],
                ]);
            }

            if (! Turnstile::passes($request, (string) $request->input('turnstile_token', ''))) {
                return new JsonResponse([
                    'ok' => false,
                    'results' => [],
                    'client_actions' => [],
                    'errors' => [0 => ['type' => 'challenge_failed', 'message' => 'We could not verify you are human. Reload the page and try again.']],
                ], 422);
            }
        }

        // With a signed-in portal user, `current_user` resolves — so an action
        // can stamp their id onto a record it creates, and a row_filter can
        // then keep that record to them. Without one it stays absent and such a
        // filter matches nothing, the safe direction for a stranger.
        $context = [
            'params' => $request->input('params', []) ?? [],
            'form' => $request->input('form', []) ?? [],
            'row' => $request->input('row', []) ?? [],
            '__access' => $access,
            '__actor' => null,
        ];
        if ($portalUser !== null) {
            $context['current_user'] = $portalUser->toExpressionContext();
        }

        $results = [];
        $clientActions = [];
        $errors = [];
        $ok = true;

        foreach ($actions as $i => $action) {
            $type = $action['type'] ?? '';

            if (in_array($type, self::CLIENT_SIDE, true)) {
                $clientActions[] = $this->resolveClientAction($action, $context);
                $results[] = ['index' => $i, 'type' => $type, 'ok' => true];

                continue;
            }

            if (! in_array($type, self::SERVER_SIDE, true)) {
                $errors[$i] = [
                    'type' => 'action_not_public',
                    'message' => "'{$type}' cannot run from a public page.",
                ];
                $ok = false;

                continue;
            }

            // Gate the object BEFORE executing. The executor re-checks too, but
            // refusing here keeps the reason precise and never starts the write.
            $objectId = (string) ($action['object_id'] ?? '');
            $capability = self::REQUIRED_CAPABILITY[$type];
            if ($objectId === '' || ! $access->can($objectId, $capability)) {
                $errors[$i] = [
                    'type' => 'forbidden',
                    'message' => 'This page cannot make that change.',
                ];
                $ok = false;

                continue;
            }

            try {
                // user: null — an anonymous write. The record.created /
                // record.updated triggers still fire inside the executor, which
                // is how a portal submission reaches the app's automation.
                $result = $this->executor->execute($app, $manifest, $action, $context, null);
                $results[] = ['index' => $i, 'type' => $type, 'ok' => true] + $result;
                if (isset($result['record_id']) && $result['record_id'] !== null) {
                    $context['record'] = ['id' => $result['record_id'], 'data' => $result['data'] ?? []];
                }
            } catch (RecordValidationException $e) {
                $errors[$i] = ['type' => 'validation', 'fields' => $e->errors];
                $ok = false;
            } catch (\Throwable $e) {
                // The visitor gets a generic message; the detail goes to the log.
                // An internal error string on a public surface is reconnaissance.
                Log::error('Public portal action failed', [
                    'app_id' => $app->id,
                    'action' => $type,
                    'error' => $e->getMessage(),
                ]);
                $errors[$i] = ['type' => 'server_error', 'message' => 'Something went wrong. Please try again.'];
                $ok = false;
            }
        }

        $blockData = null;
        $wantsRefresh = collect($clientActions)->contains(fn ($a) => ($a['type'] ?? null) === 'refresh');
        if ($ok && $wantsRefresh) {
            $page = $this->findViewablePage($manifest, $access, $request->input('page'));
            if ($page !== null) {
                $blocks = $this->visibility->visibleBlocks($page['blocks'] ?? [], $access, $context, $manifest['objects'] ?? []);
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

        return new JsonResponse($payload, $ok ? 200 : 422);
    }

    /**
     * The page the action came from, restricted to pages the visitor may view —
     * so a refresh can never resolve data for a page the portal does not expose.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findViewablePage(array $manifest, AppAccessContext $access, ?string $slug): ?array
    {
        $pages = array_values(array_filter(
            $manifest['pages'] ?? [],
            fn (array $p): bool => $access->canViewPage($p['id'] ?? ''),
        ));
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
