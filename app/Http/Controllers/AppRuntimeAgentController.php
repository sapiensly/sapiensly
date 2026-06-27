<?php

namespace App\Http\Controllers;

use App\Jobs\RunRuntimeAgentJob;
use App\Models\App;
use App\Models\RuntimeAgentConversation;
use App\Models\RuntimeAgentMessage;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\AppActionExecutor;
use App\Services\Records\RecordValidationException;
use App\Services\Runtime\RuntimeAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HTTP surface for a built app's embedded runtime agent (builder power #3, read
 * slice). End-users start a conversation and send messages; each message is
 * persisted with a streaming placeholder and answered by RunRuntimeAgentJob,
 * which streams the reply over Reverb. The agent must be enabled in the app's
 * manifest, and everything is scoped to the requesting user's tenant.
 */
class AppRuntimeAgentController extends Controller
{
    public function __construct(
        private AppManifestService $manifestService,
        private RuntimeAgentService $agent,
        private AppActionExecutor $executor,
        private AppAccessResolver $accessResolver,
    ) {}

    public function startConversation(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolveAppWithAgent($request, $appSlug);
        $conversation = $this->agent->startConversation($app, $request->user());

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => [],
        ]);
    }

    public function sendMessage(Request $request, string $appSlug): JsonResponse
    {
        $app = $this->resolveAppWithAgent($request, $appSlug);

        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $conversation = RuntimeAgentConversation::query()
            ->where('id', $data['conversation_id'])
            ->where('app_id', $app->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($conversation === null) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        RuntimeAgentMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $data['message'],
            'status' => 'none',
        ]);

        $placeholder = RuntimeAgentMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);

        RunRuntimeAgentJob::dispatch($placeholder->id, $data['message']);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->refresh()->messages->map(fn (RuntimeAgentMessage $m) => $this->messageDto($m))->all(),
            'latest_message_id' => $placeholder->id,
            'streaming' => true,
        ]);
    }

    /**
     * Approve a pending action proposal (builder power #3 gate): execute its
     * actions through the SAME write path the runtime UI uses, as the approving
     * user. This is the only place an agent-proposed write actually mutates —
     * Rule 2. Failures leave the proposal pending so the user can retry.
     */
    public function approveAction(Request $request, string $appSlug, string $messageId): JsonResponse
    {
        $app = $this->resolveAppWithAgent($request, $appSlug);
        $message = $this->loadPendingProposal($app, $request->user()->id, $messageId);

        $manifest = $this->manifestService->getActiveManifest($app);

        // Re-authorize the proposed writes as the approving user (double gate):
        // the executor reads __access to enforce CRUD/readonly/row_filter, so a
        // proposal can never execute beyond the approver's app role.
        $access = $this->accessResolver->resolve($app, $manifest, $request->user());
        if (! $access->hasAccess) {
            abort(403, 'You do not have access to this app.');
        }

        $context = [
            'current_user' => ['id' => $request->user()->id, 'email' => $request->user()->email],
            'params' => [],
            'form' => [],
            'row' => [],
            '__access' => $access,
        ];

        $payload = (array) $message->action_payload;
        $results = [];

        try {
            foreach ($payload['actions'] ?? [] as $action) {
                $results[] = $this->executor->execute($app, $manifest, $action, $context, $request->user());
            }
        } catch (RecordValidationException $e) {
            return response()->json(['message' => 'The change was rejected: invalid data.', 'fields' => $e->errors], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message->update(['action_payload' => [...$payload, 'status' => 'executed', 'results' => $results]]);

        return response()->json(['message' => $this->messageDto($message->refresh())]);
    }

    /**
     * Dismiss a pending proposal without executing it — nothing mutates.
     */
    public function dismissAction(Request $request, string $appSlug, string $messageId): JsonResponse
    {
        $app = $this->resolveAppWithAgent($request, $appSlug);
        $message = $this->loadPendingProposal($app, $request->user()->id, $messageId);

        $message->update(['action_payload' => [...(array) $message->action_payload, 'status' => 'dismissed']]);

        return response()->json(['message' => $this->messageDto($message->refresh())]);
    }

    /**
     * Load a message that is a still-pending action proposal owned by the
     * requesting user in this app — 404 otherwise (also covers already-executed
     * or dismissed proposals, so approval/dismissal is single-shot).
     */
    private function loadPendingProposal(App $app, int $userId, string $messageId): RuntimeAgentMessage
    {
        $message = RuntimeAgentMessage::query()
            ->where('id', $messageId)
            ->where('message_type', 'action_proposal')
            ->first();

        $conversation = $message?->conversation;
        if ($message === null
            || $conversation === null
            || $conversation->app_id !== $app->id
            || $conversation->user_id !== $userId
            || (($message->action_payload['status'] ?? null) !== 'pending')) {
            throw new NotFoundHttpException('Pending proposal not found.');
        }

        return $message;
    }

    /**
     * Resolve the app for the current tenant and confirm it has an enabled
     * agent — otherwise the runtime agent surface does not exist for this app.
     */
    private function resolveAppWithAgent(Request $request, string $appSlug): App
    {
        $app = App::query()
            ->forAccountContext($request->user())
            ->where('slug', $appSlug)
            ->first();

        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        if ($manifest === null || ! ($manifest['agent']['enabled'] ?? false)) {
            throw new NotFoundHttpException("App '{$appSlug}' has no agent.");
        }

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageDto(RuntimeAgentMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'message_type' => $m->message_type,
            'action_payload' => $m->action_payload,
            'status' => $m->status,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
