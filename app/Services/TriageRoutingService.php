<?php

namespace App\Services;

use App\Models\AgentTeam;
use Prism\Prism\Tool as PrismTool;

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
     * @return array<PrismTool>
     */
    public function buildRoutingTools(AgentTeam $team): array
    {
        // Load the team's agents
        $team->load(['knowledgeAgent', 'actionAgent']);

        return [$this->buildExecutionPlanTool($team)];
    }

    /**
     * Build the create_execution_plan tool.
     *
     * This tool allows the Triage Agent to create a multi-step execution plan
     * that routes different parts of a user's message to appropriate agents.
     */
    private function buildExecutionPlanTool(AgentTeam $team): PrismTool
    {
        $availableAgents = $this->describeAvailableAgents($team);

        $tool = new PrismTool;
        $tool
            ->as('create_execution_plan')
            ->for("Analyze the user's message and create an execution plan. The plan should contain one or more steps, each routing to the appropriate agent or responding directly. {$availableAgents}")
            ->withStringParameter(
                'steps',
                $this->buildStepsDescription($team),
                required: true
            )
            ->using(fn (string $steps) => $steps);

        return $tool;
    }

    /**
     * Describe which agents are available for routing.
     */
    private function describeAvailableAgents(AgentTeam $team): string
    {
        $agents = [];

        if ($team->knowledgeAgent) {
            $name = $team->knowledgeAgent->name ?? 'Knowledge Agent';
            $agents[] = "- knowledge: {$name} - handles questions about documentation, FAQs, policies, guides, or any information lookup";
        }

        if ($team->actionAgent) {
            $name = $team->actionAgent->name ?? 'Action Agent';
            $toolNames = $team->actionAgent->tools()
                ->where('status', 'active')
                ->pluck('name')
                ->join(', ');

            $desc = "- action: {$name} - handles tasks requiring execution";
            if ($toolNames) {
                $desc .= " (capabilities: {$toolNames})";
            }
            $agents[] = $desc;
        }

        $agents[] = '- direct: respond directly for greetings, clarifications, or when no specialized agent is needed';

        return "Available agents:\n".implode("\n", $agents);
    }

    /**
     * Build the description for the steps parameter.
     */
    private function buildStepsDescription(AgentTeam $team): string
    {
        $examples = [];

        // Single step example
        $examples[] = '[{"agent":"direct","response":"Hello! How can I help you today?"}]';

        // Multi-step example based on available agents
        if ($team->knowledgeAgent && $team->actionAgent) {
            $examples[] = '[{"agent":"knowledge","query":"refund policy","urgency":"medium"},{"agent":"action","task":"check order status for #12345"}]';
        } elseif ($team->knowledgeAgent) {
            $examples[] = '[{"agent":"knowledge","query":"how to reset password","urgency":"high"}]';
        } elseif ($team->actionAgent) {
            $examples[] = '[{"agent":"action","task":"cancel subscription for user"}]';
        }

        $exampleStr = implode(' or ', $examples);

        return <<<DESC
A JSON array of execution steps. Each step must have an "agent" field ("knowledge", "action", or "direct").

For "knowledge" steps: include "query" (refined search query) and optional "urgency" (low/medium/high).
For "action" steps: include "task" (task description) and optional "context" (JSON object with additional data).
For "direct" steps: include "response" (your response text).

Analyze the user's message carefully:
- If it contains multiple questions or requests, create multiple steps
- Order steps logically (information gathering before actions)
- Use "direct" for greetings, clarifications, or simple responses

Examples: {$exampleStr}
DESC;
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
            // Fallback: treat as direct response
            return [['agent' => 'direct', 'response' => $stepsJson]];
        }

        // Normalize steps
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
