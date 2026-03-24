<?php

namespace App\Services;

use App\Ai\Tools\ExecutionPlanTool;
use App\Models\AgentTeam;

/**
 * Builds routing tools for Triage Agents to create execution plans.
 *
 * The Triage Agent analyzes user messages and creates an execution plan
 * that may include multiple steps, each routed to the appropriate agent:
 * - Knowledge Agent: For questions about documentation, FAQs, policies
 * - Action Agent: For tasks requiring execution (orders, refunds, updates)
 * - Direct Response: For greetings, clarifications, or simple questions
 */
class TriageRoutingService
{
    /**
     * Build the execution plan tool for a Triage Agent.
     *
     * @return array<ExecutionPlanTool>
     */
    public function buildRoutingTools(AgentTeam $team): array
    {
        $team->load(['knowledgeAgent', 'actionAgent']);

        return [new ExecutionPlanTool($team)];
    }

    /**
     * Parse the execution plan from the tool response.
     *
     * @return array<array{agent: string, query?: string, task?: string, response?: string, urgency?: string, context?: array}>
     */
    public function parseExecutionPlan(string $stepsJson): array
    {
        $steps = json_decode($stepsJson, true);

        if (! is_array($steps)) {
            return [['agent' => 'direct', 'response' => $stepsJson]];
        }

        $normalized = [];
        foreach ($steps as $step) {
            if (! is_array($step) || ! isset($step['agent'])) {
                continue;
            }

            $normalized[] = match ($step['agent']) {
                'knowledge' => [
                    'agent' => 'knowledge',
                    'query' => $step['query'] ?? '',
                    'urgency' => $step['urgency'] ?? 'medium',
                ],
                'action' => [
                    'agent' => 'action',
                    'task' => $step['task'] ?? '',
                    'context' => $this->parseContext($step['context'] ?? null),
                ],
                'direct' => [
                    'agent' => 'direct',
                    'response' => $step['response'] ?? '',
                ],
                default => null,
            };
        }

        return array_filter($normalized);
    }

    /**
     * Parse context from various formats.
     */
    private function parseContext(mixed $context): array
    {
        if (is_array($context)) {
            return $context;
        }

        if (is_string($context)) {
            $decoded = json_decode($context, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
