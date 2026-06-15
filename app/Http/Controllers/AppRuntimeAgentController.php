<?php

namespace App\Http\Controllers;

use App\Jobs\RunRuntimeAgentJob;
use App\Models\App;
use App\Models\RuntimeAgentConversation;
use App\Models\RuntimeAgentMessage;
use App\Services\Manifest\AppManifestService;
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
