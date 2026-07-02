<?php

namespace App\Mcp\Tools\Chats;

use App\Mcp\Tools\SapiensTool;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Tool;
use App\Models\User;
use App\Services\Chat\ChatAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Retrieve one chat conversation by id with its full transcript (messages in chronological order) plus metadata and any rolling summary — for QA, review, diagnosis, or to summarize it client-side. Includes the diagnostic context of each turn: the agent and project the chat is bound to, the tool policy (agent / explicit / auto) with the effective tool set resolved to name/type/status, and per message the data sources used, agent consultations made, and attachments. Use list_chats or search_chat_messages to find the chat_id.')]
class GetChatTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chat_id' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $chat = Chat::query()->forAccountContext($user)->findOrFail($validated['chat_id']);
        } catch (ModelNotFoundException) {
            return Response::error("No chat '{$validated['chat_id']}' is visible to you.");
        }

        $limit = $validated['limit'] ?? 200;
        $order = $validated['order'] ?? 'asc';

        // Take the most recent $limit messages, then present oldest-first unless
        // the caller asked otherwise — so a long chat returns its latest turns.
        $messages = $chat->messages()
            ->with('attachments')
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($order === 'asc') {
            $messages = $messages->reverse()->values();
        }

        return Response::json([
            'chat' => [
                'chat_id' => $chat->id,
                'title' => $chat->title,
                'model' => $chat->model,
                'agent_id' => $chat->agent_id,
                'agent_name' => $chat->agent?->name,
                'project' => $chat->project === null ? null : [
                    'project_id' => $chat->project->id,
                    'name' => $chat->project->name,
                ],
                'mode' => $chat->mode,
                'tool_policy' => $this->toolPolicy($chat),
                'attached_tools' => $this->attachedTools($chat, $user),
                'summary' => $chat->summary,
                'archived' => $chat->archived_at !== null,
                'created_at' => $chat->created_at?->toIso8601String(),
                'last_message_at' => $chat->last_message_at?->toIso8601String(),
            ],
            'messages' => $messages->map(fn (ChatMessage $m): array => array_filter([
                'message_id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'model' => $m->model,
                'agent_id' => $m->agent_id,
                'message_type' => $m->message_type,
                'status' => $m->status,
                'error' => $m->error,
                'used_sources' => $m->agent_data_context,
                'consultations' => $m->consultation_context,
                'action' => $m->action_payload,
                'attachments' => $m->attachments->map(fn ($a): array => [
                    'name' => $a->original_name,
                    'mime' => $a->mime,
                    'size_bytes' => $a->size_bytes,
                ])->values()->all(),
                'created_at' => $m->created_at?->toIso8601String(),
            ], fn ($v) => $v !== null && $v !== '' && $v !== []))->values(),
            'message_count' => $messages->count(),
        ]);
    }

    /**
     * How the chat's turns get their tools, mirroring ChatAiService:
     * `agent` — a selected agent governs its own tool set;
     * `explicit` — the composer restricted the selection (chat.tool_ids);
     * `auto` — no restriction: every active connector auto-activates.
     */
    private function toolPolicy(Chat $chat): string
    {
        if ($chat->agent_id !== null) {
            return 'agent';
        }

        return empty($chat->tool_ids) ? 'auto' : 'explicit';
    }

    /**
     * The tools the chat's turns can actually call, resolved to
     * name/type/status — the #1 question when diagnosing "why didn't it use
     * tool X". Follows the tool policy: the agent's active tools, the
     * composer's explicit selection (deleted or unresolvable ids kept and
     * flagged rather than silently dropped), or the auto-activated set.
     *
     * @return list<array<string, mixed>>
     */
    private function attachedTools(Chat $chat, User $user): array
    {
        if ($chat->agent_id !== null) {
            $tools = $chat->agent?->tools()->where('status', 'active')->get() ?? collect();

            return $tools->map(fn (Tool $tool): array => $this->presentTool($tool))->values()->all();
        }

        $toolIds = array_values(array_filter((array) ($chat->tool_ids ?? []), 'is_string'));

        if ($toolIds === []) {
            $owner = $chat->user ?? $user;
            $autoIds = app(ChatAiService::class)->autoAttachableToolIds($owner);

            return Tool::query()->whereIn('id', $autoIds)->get()
                ->map(fn (Tool $tool): array => $this->presentTool($tool))
                ->values()
                ->all();
        }

        $byId = Tool::query()->withTrashed()->whereIn('id', $toolIds)->get()->keyBy('id');

        return array_map(function (string $id) use ($byId): array {
            $tool = $byId->get($id);
            if ($tool === null) {
                return ['tool_id' => $id, 'missing' => true];
            }

            return $this->presentTool($tool);
        }, $toolIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTool(Tool $tool): array
    {
        return array_filter([
            'tool_id' => $tool->id,
            'name' => $tool->name,
            'type' => $tool->type?->value,
            'status' => $tool->status?->value,
            'deleted' => $tool->trashed() ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chat_id' => $schema->string()->description('The id of the chat to retrieve.')->required(),
            'limit' => $schema->integer()->description('Max messages to return — the most recent ones (default 200, max 500).'),
            'order' => $schema->string()->enum(['asc', 'desc'])->description('Order of returned messages: asc = oldest first (default), desc = newest first.'),
        ];
    }
}
