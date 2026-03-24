<?php

namespace App\Ai\Tools;

use App\Models\AgentTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ExecutionPlanTool implements Tool
{
    private string $availableAgents;

    private string $stepsDescription;

    public function __construct(AgentTeam $team)
    {
        $this->availableAgents = $this->describeAvailableAgents($team);
        $this->stepsDescription = $this->buildStepsDescription($team);
    }

    public function name(): string
    {
        return 'create_execution_plan';
    }

    public function description(): string
    {
        return "Analyze the user's message and create an execution plan. The plan should contain one or more steps, each routing to the appropriate agent or responding directly. {$this->availableAgents}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'steps' => $schema
                ->string()
                ->description($this->stepsDescription)
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        return $request->data('steps', '[]');
    }

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

    private function buildStepsDescription(AgentTeam $team): string
    {
        $examples = [];

        $examples[] = '[{"agent":"direct","response":"Hello! How can I help you today?"}]';

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
}
