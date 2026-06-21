<?php

namespace App\Support\Chat;

use App\Models\Agent;
use App\Models\ChatMessage;

/**
 * Single source of truth for the chat-message DTO sent to the frontend, used by
 * the controllers (page load + send response) and the broadcast events so the
 * agent / action fields never drift between them.
 */
class ChatMessagePresenter
{
    /**
     * @param  array<string, array{id: string, name: string}>  $agentMeta  optional
     *                                                                     preloaded agent_id => {id,name} map to avoid per-message lookups
     * @return array<string, mixed>
     */
    public static function present(ChatMessage $message, array $agentMeta = []): array
    {
        $agent = null;
        if ($message->agent_id !== null) {
            $agent = $agentMeta[$message->agent_id]
                ?? self::lookupAgent($message->agent_id);
        }

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'model' => $message->model,
            'status' => $message->status,
            'error' => $message->error,
            'created_at' => $message->created_at?->toIso8601String(),
            'agent_id' => $message->agent_id,
            'agent' => $agent,
            'message_type' => $message->message_type ?? 'text',
            'agent_data_context' => $message->agent_data_context,
            'action_payload' => $message->action_payload,
            'consultation_context' => $message->consultation_context,
            'attachments' => $message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'size_bytes' => $a->size_bytes,
                'url' => route('chat.attachments.show', ['chat' => $message->chat_id, 'attachment' => $a->id]),
            ])->values(),
        ];
    }

    /**
     * Build an agent_id => {id,name} map for a set of messages in one query.
     *
     * @param  iterable<ChatMessage>  $messages
     * @return array<string, array{id: string, name: string}>
     */
    public static function agentMetaFor(iterable $messages): array
    {
        $ids = [];
        foreach ($messages as $m) {
            if ($m->agent_id !== null) {
                $ids[$m->agent_id] = true;
            }
        }
        if (empty($ids)) {
            return [];
        }

        return Agent::query()
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'name'])
            ->keyBy('id')
            ->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name])
            ->all();
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private static function lookupAgent(string $agentId): ?array
    {
        $agent = Agent::query()->find($agentId, ['id', 'name']);

        return $agent === null ? null : ['id' => $agent->id, 'name' => $agent->name];
    }
}
