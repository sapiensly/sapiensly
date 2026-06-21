<?php

namespace App\Mcp\Tools\Agents;

use App\Mcp\Tools\SapiensTool;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List your existing agent conversations so you can resume one. Pass an agent_id to filter to a single agent. Use a returned conversation_id with invoke_agent to continue that thread.')]
class ListConversationsTool extends SapiensTool
{
    protected const ABILITY = 'agents:invoke';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $conversations = Conversation::query()
            ->where('user_id', $user->id)
            ->when($validated['agent_id'] ?? null, fn ($q, $agentId) => $q->where('agent_id', $agentId))
            ->withCount('messages')
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return Response::json([
            'conversations' => $conversations->map(fn (Conversation $c) => [
                'conversation_id' => $c->id,
                'agent_id' => $c->agent_id,
                'title' => $c->title,
                'message_count' => $c->messages_count,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Optional. Only list conversations with this agent.'),
        ];
    }
}
