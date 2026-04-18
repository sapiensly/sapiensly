<?php

namespace App\Services;

use App\Enums\FlowActionType;
use App\Models\Agent;
use App\Models\Flow;

class FlowExecutorService
{
    /**
     * Determine if a flow should be activated for a conversation.
     */
    public function shouldActivateFlow(Agent $agent, string $userMessage, ?array $flowState): bool
    {
        // If already in a flow, continue it
        if ($flowState && ! ($flowState['completed'] ?? false)) {
            return true;
        }

        // Check if agent has an active flow
        $flow = $agent->activeFlow();
        if (! $flow) {
            return false;
        }

        $startNode = $flow->getStartNode();
        if (! $startNode) {
            return false;
        }

        $trigger = $startNode['data']['trigger'] ?? 'conversation_start';

        return match ($trigger) {
            'always' => true,
            'conversation_start' => $flowState === null,
            'keyword' => $this->matchesKeywords($userMessage, $startNode['data']['keywords'] ?? []),
            default => false,
        };
    }

    /**
     * Initialize flow state at the start node.
     *
     * @return array<string, mixed>
     */
    public function initializeFlow(Flow $flow): array
    {
        $startNode = $flow->getStartNode();

        return [
            'flow_id' => $flow->id,
            'current_node_id' => $startNode['id'],
            'started_at' => now()->toISOString(),
            'history' => [$startNode['id']],
            'completed' => false,
        ];
    }

    /**
     * Process user input through the flow state machine.
     */
    public function processInput(Flow $flow, array $flowState, string $userInput): FlowAction
    {
        $currentNodeId = $flowState['current_node_id'];
        $currentNode = $flow->getNode($currentNodeId);

        if (! $currentNode) {
            return $this->endFlow($flowState, 'resume_conversation');
        }

        return match ($currentNode['type']) {
            'start' => $this->processStartNode($flow, $flowState, $currentNode),
            'menu' => $this->processMenuInput($flow, $flowState, $currentNode, $userInput),
            'condition' => $this->processConditionInput($flow, $flowState, $currentNode, $userInput),
            'agent_handoff' => $this->processAgentHandoff($flowState, $currentNode),
            'message' => $this->processMessageNode($flow, $flowState, $currentNode),
            'connector' => $this->processConnectorNode($flow, $flowState, $currentNode),
            'end' => $this->endFlow($flowState, $currentNode['data']['action'] ?? 'resume_conversation'),
            default => $this->endFlow($flowState, 'resume_conversation'),
        };
    }

    /**
     * Advance to a specific node (e.g., after LLM classification).
     */
    public function advanceToNode(Flow $flow, array $flowState, string $nodeId): FlowAction
    {
        $node = $flow->getNode($nodeId);
        if (! $node) {
            return $this->endFlow($flowState, 'resume_conversation');
        }

        $flowState['current_node_id'] = $nodeId;
        $flowState['history'][] = $nodeId;

        return $this->executeNode($flow, $flowState, $node);
    }

    /**
     * Execute a node's action (for nodes that produce output immediately).
     */
    private function executeNode(Flow $flow, array $flowState, array $node): FlowAction
    {
        return match ($node['type']) {
            'menu' => new FlowAction(
                FlowActionType::ShowMenu,
                [
                    'message' => $node['data']['message'] ?? '',
                    'options' => $node['data']['options'] ?? [],
                ],
                $flowState
            ),
            'message' => $this->processMessageNode($flow, $flowState, $node),
            'connector' => $this->processConnectorNode($flow, $flowState, $node),
            'agent_handoff' => $this->processAgentHandoff($flowState, $node),
            'end' => $this->endFlow($flowState, $node['data']['action'] ?? 'resume_conversation'),
            'condition' => new FlowAction(
                FlowActionType::ShowMenu, // Condition nodes wait for input
                ['message' => '', 'options' => []],
                $flowState
            ),
            default => $this->endFlow($flowState, 'resume_conversation'),
        };
    }

    private function processStartNode(Flow $flow, array $flowState, array $node): FlowAction
    {
        // Follow the edge from start to the next node
        $edges = $flow->getEdgesFrom($node['id']);
        if (empty($edges)) {
            return $this->endFlow($flowState, 'resume_conversation');
        }

        $nextNodeId = $edges[0]['target'];

        return $this->advanceToNode($flow, $flowState, $nextNodeId);
    }

    private function processMenuInput(Flow $flow, array $flowState, array $node, string $userInput): FlowAction
    {
        $options = $node['data']['options'] ?? [];
        $matchedOptionId = $this->matchMenuOption($options, $userInput);

        if ($matchedOptionId === null) {
            // No match — re-show the menu
            return new FlowAction(
                FlowActionType::ShowMenu,
                [
                    'message' => $node['data']['message'] ?? '',
                    'options' => $options,
                ],
                $flowState
            );
        }

        // Find the edge for this option
        $edges = $flow->getEdgesFrom($node['id'], $matchedOptionId);
        if (empty($edges)) {
            return $this->endFlow($flowState, 'resume_conversation');
        }

        $nextNodeId = $edges[0]['target'];

        return $this->advanceToNode($flow, $flowState, $nextNodeId);
    }

    private function processConditionInput(Flow $flow, array $flowState, array $node, string $userInput): FlowAction
    {
        $matchType = $node['data']['match_type'] ?? 'contains';
        $rules = $node['data']['rules'] ?? [];

        if ($matchType === 'llm_classification') {
            return new FlowAction(
                FlowActionType::AwaitLlmClassification,
                [
                    'rules' => $rules,
                    'user_input' => $userInput,
                    'node_id' => $node['id'],
                ],
                $flowState
            );
        }

        // Try to match locally
        foreach ($rules as $rule) {
            if ($this->matchesCondition($matchType, $rule['pattern'] ?? '', $userInput)) {
                $edges = $flow->getEdgesFrom($node['id'], $rule['id']);
                if (! empty($edges)) {
                    return $this->advanceToNode($flow, $flowState, $edges[0]['target']);
                }
            }
        }

        // No match — try default edge
        $defaultEdges = $flow->getEdgesFrom($node['id'], 'default');
        if (! empty($defaultEdges)) {
            return $this->advanceToNode($flow, $flowState, $defaultEdges[0]['target']);
        }

        return $this->endFlow($flowState, 'resume_conversation');
    }

    private function processAgentHandoff(array $flowState, array $node): FlowAction
    {
        $flowState['completed'] = true;

        return new FlowAction(
            FlowActionType::AgentHandoff,
            [
                'target_agent' => $node['data']['target_agent'] ?? 'triage_llm',
                'context' => $node['data']['context'] ?? null,
                'message' => $node['data']['message'] ?? null,
            ],
            $flowState
        );
    }

    private function processMessageNode(Flow $flow, array $flowState, array $node): FlowAction
    {
        // Send the message, then follow the edge to the next node
        $edges = $flow->getEdgesFrom($node['id']);

        if (empty($edges)) {
            // Message with no outgoing edge — end flow
            $flowState['completed'] = true;

            return new FlowAction(
                FlowActionType::SendMessage,
                ['message' => $node['data']['message'] ?? ''],
                $flowState
            );
        }

        // Return message action — caller should then advance to next node
        $nextNodeId = $edges[0]['target'];
        $flowState['current_node_id'] = $nextNodeId;
        $flowState['history'][] = $nextNodeId;

        return new FlowAction(
            FlowActionType::SendMessage,
            [
                'message' => $node['data']['message'] ?? '',
                'next_node_id' => $nextNodeId,
            ],
            $flowState
        );
    }

    private function processConnectorNode(Flow $flow, array $flowState, array $node): FlowAction
    {
        $targetNodeId = $node['data']['target_node_id'] ?? '__start__';

        // If target is __start__, go to the start node
        if ($targetNodeId === '__start__') {
            $startNode = $flow->getStartNode();
            if ($startNode) {
                return $this->processStartNode($flow, $flowState, $startNode);
            }

            return $this->endFlow($flowState, 'resume_conversation');
        }

        // Otherwise, go to the specified node (menu, etc.)
        return $this->advanceToNode($flow, $flowState, $targetNodeId);
    }

    private function endFlow(array $flowState, string $action): FlowAction
    {
        $flowState['completed'] = true;

        return new FlowAction(
            FlowActionType::End,
            ['action' => $action],
            $flowState
        );
    }

    /**
     * Match user input to a menu option by index or label.
     */
    private function matchMenuOption(array $options, string $userInput): ?string
    {
        $input = trim($userInput);
        $inputLower = mb_strtolower($input);

        // Match by 1-based index
        if (is_numeric($input)) {
            $index = (int) $input - 1;
            if (isset($options[$index])) {
                return $options[$index]['id'];
            }
        }

        // Match by exact label
        foreach ($options as $option) {
            if (mb_strtolower($option['label']) === $inputLower) {
                return $option['id'];
            }
        }

        // Match by partial label (contains)
        foreach ($options as $option) {
            if (str_contains(mb_strtolower($option['label']), $inputLower) && strlen($input) >= 3) {
                return $option['id'];
            }
        }

        return null;
    }

    private function matchesCondition(string $matchType, string $pattern, string $input): bool
    {
        $inputLower = mb_strtolower(trim($input));
        $patternLower = mb_strtolower($pattern);

        return match ($matchType) {
            'exact' => $inputLower === $patternLower,
            'contains' => str_contains($inputLower, $patternLower),
            'regex' => (bool) @preg_match($pattern, $input),
            default => false,
        };
    }

    private function matchesKeywords(string $message, array $keywords): bool
    {
        $messageLower = mb_strtolower($message);

        foreach ($keywords as $keyword) {
            if (str_contains($messageLower, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
