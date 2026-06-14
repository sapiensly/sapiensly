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
