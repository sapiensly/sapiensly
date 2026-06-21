<?php

namespace App\Mcp\Tools\Chatbots;

use App\Mcp\Tools\SapiensTool;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\User;

/**
 * Shared base for chatbot/bot-flow tools: resolves a chatbot within the caller's
 * account context and builds the agent roster the scaffolder expects.
 */
abstract class ChatbotTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    protected function resolveChatbot(string $id, User $user): Chatbot
    {
        return Chatbot::query()->forAccountContext($user)->findOrFail($id);
    }

    /**
     * The agents available to a bot flow, grouped by role — the exact shape
     * BotFlowScaffolder expects (mirrors BotFlowController::getAvailableAgents).
     *
     * @return array{triage: list<array{id: string, name: string, model: string}>, knowledge: list<array<string, mixed>>, action: list<array<string, mixed>>}
     */
    protected function availableAgents(User $user): array
    {
        $byType = Agent::query()
            ->forAccountContext($user)
            ->get(['id', 'name', 'type', 'model'])
            ->groupBy(fn (Agent $a) => $a->type->value);

        $shape = fn ($collection) => $collection->map(fn (Agent $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'model' => $a->model,
        ])->values()->all();

        return [
            'triage' => $shape($byType->get('triage', collect())),
            'knowledge' => $shape($byType->get('knowledge', collect())),
            'action' => $shape($byType->get('action', collect())),
        ];
    }

    /**
     * The JSON shape returned for a single chatbot: config, channel, flow and the
     * agent roster the flow hands off to. Shared by get/create/update_chatbot.
     *
     * @return array<string, mixed>
     */
    protected function chatbotPayload(Chatbot $chatbot): array
    {
        $chatbot->loadMissing('channel', 'botFlow');
        $flow = $chatbot->botFlow;

        return [
            'id' => $chatbot->id,
            'name' => $chatbot->name,
            'description' => $chatbot->description,
            'status' => $chatbot->status?->value,
            'visibility' => $chatbot->visibility?->value,
            'channel' => $chatbot->channel === null ? null : [
                'id' => $chatbot->channel->id,
                'name' => $chatbot->channel->name,
                'type' => $chatbot->channel->channel_type?->value,
                'status' => $chatbot->channel->status?->value,
            ],
            'allowed_origins' => $chatbot->allowed_origins ?? [],
            'config' => $chatbot->config ?? [],
            'has_flow' => $flow !== null,
            'flow' => $flow === null ? null : [
                'id' => $flow->id,
                'status' => $flow->status?->value,
                'version' => $flow->version,
            ],
            'roster' => $flow === null ? [] : collect($flow->roster())
                ->map(fn (?Agent $agent) => $agent === null ? null : [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                ])
                ->all(),
        ];
    }
}
