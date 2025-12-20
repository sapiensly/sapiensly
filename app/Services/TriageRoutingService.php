<?php

namespace App\Services;

use App\Models\AgentTeam;
use Prism\Prism\Tool as PrismTool;

/**
 * Builds routing tools for Triage Agents to decide where to route requests.
 *
 * The Triage Agent uses these tools to classify user intent and route to:
 * - Knowledge Agent: For questions about documentation, FAQs, policies
 * - Action Agent: For tasks requiring execution (orders, refunds, updates)
 * - Direct Response: For greetings, clarifications, or simple questions
 */
class TriageRoutingService
{
    /**
     * Build routing tools for a Triage Agent based on the team's agents.
     *
     * @return array<PrismTool>
     */
    public function buildRoutingTools(AgentTeam $team): array
    {
        $tools = [];

        // Load the team's agents
        $team->load(['knowledgeAgent', 'actionAgent']);

        // Route to Knowledge Agent (RAG)
        if ($team->knowledgeAgent) {
            $tools[] = $this->buildRouteToKnowledgeTool($team);
        }

        // Route to Action Agent (Tools)
        if ($team->actionAgent) {
            $tools[] = $this->buildRouteToActionTool($team);
        }

        // Respond directly (always available)
        $tools[] = $this->buildRespondDirectlyTool();

        return $tools;
    }

    /**
     * Build the route_to_knowledge tool.
     */
    private function buildRouteToKnowledgeTool(AgentTeam $team): PrismTool
    {
        $kbName = $team->knowledgeAgent->name ?? 'Knowledge Agent';

        $tool = new PrismTool;
        $tool
            ->as('route_to_knowledge')
            ->for("Route to the {$kbName} for questions about documentation, FAQs, policies, how-to guides, or any information lookup that can be answered from the knowledge base.")
            ->withStringParameter(
                'query',
                'The refined query to search in the knowledge base. Rephrase the user\'s question to optimize for semantic search.',
                required: true
            )
            ->withStringParameter(
                'urgency',
                'The urgency level: low, medium, or high. Default to medium if unclear.',
                required: false
            )
            ->using(function (string $query, ?string $urgency = null) {
                return [
                    'action' => 'knowledge',
                    'query' => $query,
                    'urgency' => $urgency ?? 'medium',
                ];
            });

        return $tool;
    }

    /**
     * Build the route_to_action tool.
     */
    private function buildRouteToActionTool(AgentTeam $team): PrismTool
    {
        $actionName = $team->actionAgent->name ?? 'Action Agent';

        // Get the action agent's tools for better description
        $toolNames = $team->actionAgent->tools()
            ->where('status', 'active')
            ->pluck('name')
            ->join(', ');

        $description = "Route to the {$actionName} for tasks requiring execution or real-world operations.";
        if ($toolNames) {
            $description .= " Available capabilities: {$toolNames}.";
        }

        $tool = new PrismTool;
        $tool
            ->as('route_to_action')
            ->for($description)
            ->withStringParameter(
                'task',
                'A clear description of the task to perform. Include all relevant details from the user\'s request.',
                required: true
            )
            ->withStringParameter(
                'context',
                'Additional context or parameters for the task as a JSON string (optional).',
                required: false
            )
            ->using(function (string $task, ?string $context = null) {
                $contextData = [];
                if ($context) {
                    $decoded = json_decode($context, true);
                    if (is_array($decoded)) {
                        $contextData = $decoded;
                    }
                }

                return [
                    'action' => 'action',
                    'task' => $task,
                    'context' => $contextData,
                ];
            });

        return $tool;
    }

    /**
     * Build the respond_directly tool for simple interactions.
     */
    private function buildRespondDirectlyTool(): PrismTool
    {
        $tool = new PrismTool;
        $tool
            ->as('respond_directly')
            ->for('Respond directly to the user without routing to another agent. Use this for: greetings, clarifying questions, simple conversational responses, or when the request doesn\'t require specialized knowledge or actions.')
            ->withStringParameter(
                'response',
                'Your response to the user. Be helpful, friendly, and concise.',
                required: true
            )
            ->using(function (string $response) {
                return [
                    'action' => 'direct',
                    'response' => $response,
                ];
            });

        return $tool;
    }
}
