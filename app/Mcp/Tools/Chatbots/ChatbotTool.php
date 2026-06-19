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
}
