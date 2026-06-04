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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChatMessageController extends Controller
{
    public function __construct(private readonly AiProviderService $providers) {}

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

        $placeholder = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => null,
            'model' => $model,
            'status' => 'pending',
        ]);

        // Remember the chosen model/agent + enabled tools on the chat for next time.
        $toolIds = $this->accessibleToolIds($data['tool_ids'] ?? [], $user);
        $chat->update(['model' => $model, 'agent_id' => $agentId, 'tool_ids' => $toolIds ?: null]);

        RunChatAiJob::dispatch(
            $placeholder->id,
            $content,
            $model,
            (bool) ($data['web_search'] ?? false),
            $toolIds,
        );

        return new JsonResponse([
            'user_message' => $this->present($userMessage->load('attachments'), $chat),
            'placeholder' => $this->present($placeholder, $chat),
        ], 201);
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

    /**
     * @return array<string, mixed>
     */
    private function present(ChatMessage $message, Chat $chat): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'model' => $message->model,
            'status' => $message->status,
            'error' => $message->error,
            'created_at' => $message->created_at?->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'size_bytes' => $a->size_bytes,
                'url' => route('chat.attachments.show', ['chat' => $chat->id, 'attachment' => $a->id]),
            ])->values(),
        ];
    }
}
