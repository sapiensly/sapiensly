<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Jobs\RunChatAiJob;
use App\Models\Agent;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\Tool;
use App\Models\User;
use App\Services\AiProviderService;
use App\Services\Chat\ChatAiService;
use App\Services\Chat\MentionParser;
use App\Services\Chat\MultiAgentDispatcher;
use App\Services\Express\ExpressIntentRouter;
use App\Services\Express\ExpressLauncher;
use App\Support\Chat\ChatMessagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChatMessageController extends Controller
{
    /** Max agent invocations per conversation per minute (per org via the chat). */
    private const AGENT_RATE_MAX = 10;

    public function __construct(
        private readonly AiProviderService $providers,
        private readonly MentionParser $mentions,
        private readonly MultiAgentDispatcher $multiAgent,
        private readonly ExpressIntentRouter $expressRouter,
        private readonly ExpressLauncher $express,
    ) {}

    /**
     * Cooperatively cancel an in-flight assistant turn. Sets a cache flag the
     * streaming worker polls; it then finalizes the partial reply.
     */
    public function stop(Request $request, Chat $chat): JsonResponse
    {
        if ($chat->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('Chat not found.');
        }

        $messageId = (string) $request->input('message_id', '');
        $belongs = $messageId !== '' && ChatMessage::query()
            ->where('id', $messageId)
            ->where('chat_id', $chat->id)
            ->exists();

        if ($belongs) {
            Cache::put(ChatAiService::STOP_CACHE_PREFIX.$messageId, true, now()->addMinutes(10));
        }

        return new JsonResponse(['stopped' => $belongs]);
    }

    public function store(StoreChatMessageRequest $request, Chat $chat): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $content = trim((string) ($data['content'] ?? ''));

        // The picker emits either a model id or `agent:{id}`. An agent selection
        // runs the turn as that agent (its model/prompt/KBs/tools); a plain model
        // clears any previously selected agent.
        [$model, $agentId] = $this->resolveSelection($data['model'] ?? null, $user, $chat);

        $userMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'user',
            'content' => $content !== '' ? $content : null,
            'model' => $model,
            'status' => 'complete',
        ]);

        // Link any pre-uploaded attachments that belong to this chat + user.
        $attachmentIds = $data['attachment_ids'] ?? [];
        if (! empty($attachmentIds)) {
            ChatAttachment::query()
                ->whereIn('id', $attachmentIds)
                ->where('chat_id', $chat->id)
                ->where('user_id', $user->id)
                ->whereNull('chat_message_id')
                ->update(['chat_message_id' => $userMessage->id]);
        }

        // Multi-agent (@mention) path: each resolved agent answers in turn, then
        // the thread is synthesized into an action proposal. Takes precedence over
        // the single-agent / plain-model path.
        $mentioned = $this->mentions->resolve($user, $data['mentioned_agent_ids'] ?? [], $content);
        /** @var Collection<int, Agent> $agents */
        $agents = $mentioned['agents'];

        if ($agents->isNotEmpty()) {
            return $this->dispatchMultiAgent($chat, $user, $userMessage, $content, $agents, $mentioned['capped']);
        }

        // G-0 Express autoroute: a clear "build me a dashboard" over a live MCP
        // source builds via the deterministic Express pipeline instead of a
        // free-form chat turn. Only plain single-model turns qualify — a
        // selected agent, an @mention (handled above) or an attachment is a
        // deliberate other intent, so those keep the conversational path.
        if ($agentId === null
            && empty($attachmentIds)
            && $content !== ''
            && $this->expressRouter->shouldRunExpressForUser($content, $user)) {
            return $this->launchExpressFromChat($chat, $user, $userMessage, $content, $model);
        }

        $placeholder = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => null,
            'model' => $model,
            'status' => 'pending',
        ]);

        // Remember the chosen model/agent + enabled tools on the chat for next time.
        $toolIds = $this->accessibleToolIds($data['tool_ids'] ?? [], $user);
        $chat->update(['model' => $model, 'agent_id' => $agentId, 'tool_ids' => $toolIds ?: null, 'mode' => 'single']);

        RunChatAiJob::dispatch(
            $placeholder->id,
            $content,
            $model,
            (bool) ($data['web_search'] ?? false),
            $toolIds,
        );

        return new JsonResponse([
            'user_message' => ChatMessagePresenter::present($userMessage->load('attachments')),
            'placeholder' => ChatMessagePresenter::present($placeholder),
        ], 201);
    }

    /**
     * Roster the mentioned agents, chain one streaming turn per agent (in mention
     * order so each sees the prior responses) and synthesize at the end.
     *
     * @param  Collection<int, Agent>  $agents
     */
    private function dispatchMultiAgent(Chat $chat, User $user, ChatMessage $userMessage, string $content, Collection $agents, bool $capped): JsonResponse
    {
        $rateKey = 'chat-agents:'.($user->organization_id ?? 'u'.$user->id).':'.$chat->id;
        if (RateLimiter::tooManyAttempts($rateKey, self::AGENT_RATE_MAX)) {
            return new JsonResponse([
                'message' => 'Too many agent requests in this conversation. Please wait a moment.',
            ], 429);
        }
        foreach ($agents as $ignored) {
            RateLimiter::hit($rateKey, 60);
        }

        $systemNotice = $this->multiAgent->dispatch($chat, $content, $agents, $capped);

        $payload = [
            'user_message' => ChatMessagePresenter::present($userMessage->load('attachments')),
            'mode' => 'multi_agent',
            'agent_ids' => $agents->pluck('id')->all(),
        ];
        if ($systemNotice !== null) {
            $payload['system_notice'] = ChatMessagePresenter::present($systemNotice);
        }

        return new JsonResponse($payload, 201);
    }

    /**
     * Provision a fresh app for the dashboard and launch the Express pipeline
     * into its builder conversation. The chat answers immediately with a
     * "…te avisaré cuando esté listo" message linked to the run; when the build
     * reaches a terminal state, ExpressDashboardJob flips this same message to
     * "…listo" (or an honest failure) and rebroadcasts it, so the open chat
     * updates in place. The build itself streams on the Builder's own surface
     * (progress, Detener, the stale reaper all apply there).
     */
    private function launchExpressFromChat(Chat $chat, User $user, ChatMessage $userMessage, string $prompt, ?string $model): JsonResponse
    {
        $chat->update(['model' => $model, 'agent_id' => null, 'mode' => 'single']);

        [$app, $conversation] = $this->express->provisionDashboardApp($user, $prompt);

        $assistant = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => $this->expressBuildingContent($app->name),
            'model' => $model,
            'status' => 'complete',
            'message_type' => 'text',
        ]);

        // Link the run back to this chat message so the job can flip it on
        // completion (see ExpressLauncher::notifyChatReady).
        $this->express->launch($app, $conversation, $prompt, $model, $assistant);

        return new JsonResponse([
            'user_message' => ChatMessagePresenter::present($userMessage->load('attachments')),
            'placeholder' => ChatMessagePresenter::present($assistant),
        ], 201);
    }

    /**
     * The in-progress card body: tells the user to keep chatting and that the
     * same message will announce the dashboard when the build finishes. Markdown
     * so it renders like any assistant bubble.
     */
    private function expressBuildingContent(string $appName): string
    {
        return "⏳ Estoy construyendo tu dashboard **{$appName}** con el pipeline Express. "
            .'Sigue la conversación — te avisaré aquí mismo cuando esté listo '
            .'(suele tardar entre 40 y 120 segundos).';
    }

    /**
     * Resolve the picker selection into a [model, agent_id] pair. The picker
     * sends either a model id or `agent:{id}`; an agent selection runs the turn
     * as that agent, a plain model clears any selected agent.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveSelection(?string $selection, User $user, Chat $chat): array
    {
        if (is_string($selection) && str_starts_with($selection, 'agent:')) {
            $agent = Agent::query()->forAccountContext($user)->find(substr($selection, 6));
            if ($agent !== null) {
                return [$agent->model, $agent->id];
            }
        }

        $reachable = collect($this->providers->getEnabledChatModels())->pluck('value');
        if (is_string($selection) && $reachable->contains($selection)) {
            return [$selection, null];
        }

        $fallback = $chat->agent_id === null && $reachable->contains($chat->model)
            ? $chat->model
            : $reachable->first();

        return [$fallback, null];
    }

    /**
     * Filter the requested tool ids down to active tools the user can access.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function accessibleToolIds(array $ids, User $user): array
    {
        if (empty($ids)) {
            return [];
        }

        return Tool::query()
            ->forAccountContext($user)
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->pluck('id')
            ->all();
    }
}
