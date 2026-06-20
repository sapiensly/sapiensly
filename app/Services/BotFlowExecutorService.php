<?php

namespace App\Services;

use App\Enums\BotFlowActionType;
use App\Models\BotFlow;

class BotFlowExecutorService
{
    /**
     * Determine whether a Bot Flow should activate for a conversation.
     */
    public function shouldActivateBotFlow(BotFlow $flow, string $userMessage, ?array $flowState): bool
    {
        // If already in a flow, continue it
        if ($flowState && ! ($flowState['completed'] ?? false)) {
            return true;
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
    public function initializeFlow(BotFlow $flow): array
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
     *
     * @param  array<int, array<string, mixed>>  $attachments  Normalized attachment descriptors uploaded this turn.
     */
    public function processInput(BotFlow $flow, array $flowState, string $userInput, array $attachments = []): BotFlowAction
    {
        // Expose this turn's uploads to the flow (file-input capture + condition
        // routing) and to downstream agents. Stored light (no extracted_text) so
        // the persisted flow_state stays small.
        if ($attachments !== []) {
            $flowState['variables'] = $flowState['variables'] ?? [];
            $flowState['variables']['_last_upload'] = $this->lightDescriptors($attachments);
        }

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
            'input' => $this->processInputNode($flow, $flowState, $currentNode, $userInput, $attachments),
            'human_handoff' => $this->humanHandoff($flowState, $currentNode),
            'end' => $this->endFlow($flowState, $currentNode['data']['action'] ?? 'resume_conversation'),
            default => $this->endFlow($flowState, 'resume_conversation'),
        };
    }

    /**
     * Advance to a specific node (e.g., after LLM classification).
     */
    public function advanceToNode(BotFlow $flow, array $flowState, string $nodeId): BotFlowAction
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
    private function executeNode(BotFlow $flow, array $flowState, array $node): BotFlowAction
    {
        return match ($node['type']) {
            'menu' => new BotFlowAction(
                BotFlowActionType::ShowMenu,
                [
                    'message' => $node['data']['message'] ?? '',
                    'options' => $node['data']['options'] ?? [],
                ],
                $flowState
            ),
            'message' => $this->processMessageNode($flow, $flowState, $node),
            'connector' => $this->processConnectorNode($flow, $flowState, $node),
            'agent_handoff' => $this->processAgentHandoff($flowState, $node),
            'input' => $this->promptInput($flowState, $node),
            'human_handoff' => $this->humanHandoff($flowState, $node),
            'end' => $this->endFlow($flowState, $node['data']['action'] ?? 'resume_conversation'),
            'condition' => new BotFlowAction(
                BotFlowActionType::ShowMenu, // Condition nodes wait for input
                ['message' => '', 'options' => []],
                $flowState
            ),
            default => $this->endFlow($flowState, 'resume_conversation'),
        };
    }

    private function processStartNode(BotFlow $flow, array $flowState, array $node): BotFlowAction
    {
        // Follow the edge from start to the next node
        $edges = $flow->getEdgesFrom($node['id']);
        if (empty($edges)) {
            return $this->endFlow($flowState, 'resume_conversation');
        }

        $nextNodeId = $edges[0]['target'];

        return $this->advanceToNode($flow, $flowState, $nextNodeId);
    }

    private function processMenuInput(BotFlow $flow, array $flowState, array $node, string $userInput): BotFlowAction
    {
        $options = $node['data']['options'] ?? [];
        $matchedOptionId = $this->matchMenuOption($options, $userInput);

        if ($matchedOptionId === null) {
            // No match — re-show the menu
            return new BotFlowAction(
                BotFlowActionType::ShowMenu,
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

    private function processConditionInput(BotFlow $flow, array $flowState, array $node, string $userInput): BotFlowAction
    {
        $matchType = $node['data']['match_type'] ?? 'contains';
        $rules = $node['data']['rules'] ?? [];

        if ($matchType === 'llm_classification') {
            return new BotFlowAction(
                BotFlowActionType::AwaitLlmClassification,
                [
                    'rules' => $rules,
                    'user_input' => $userInput,
                    'node_id' => $node['id'],
                ],
                $flowState
            );
        }

        // File-based routing reads the uploaded file(s) (a named variable, or
        // this turn's upload) rather than the text input.
        $isFileMatch = in_array($matchType, ['has_file', 'file_type_is'], true);
        $files = $isFileMatch ? $this->resolveConditionFiles($flowState, $node) : [];

        // Try to match locally
        foreach ($rules as $rule) {
            $matched = $isFileMatch
                ? $this->matchesFileCondition($matchType, $rule['pattern'] ?? '', $files)
                : $this->matchesCondition($matchType, $rule['pattern'] ?? '', $userInput);

            if ($matched) {
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

    private function processAgentHandoff(array $flowState, array $node): BotFlowAction
    {
        $flowState['completed'] = true;

        return new BotFlowAction(
            BotFlowActionType::AgentHandoff,
            [
                'target_agent' => $node['data']['target_agent'] ?? 'triage_llm',
                'context' => $node['data']['context'] ?? null,
                'message' => $node['data']['message'] ?? null,
            ],
            $flowState
        );
    }

    /**
     * Emit the prompt for an input node and wait for the user's reply. The
     * current node stays put (like a menu) so the next turn lands back here in
     * processInputNode to capture the answer.
     */
    private function promptInput(array $flowState, array $node): BotFlowAction
    {
        return new BotFlowAction(
            BotFlowActionType::CollectInput,
            [
                'prompt' => $node['data']['prompt'] ?? '',
                'variable' => $node['data']['variable'] ?? 'input',
                'input_type' => $node['data']['input_type'] ?? 'text',
            ],
            $flowState
        );
    }

    /**
     * Capture the user's reply into the flow's variable bag, then follow the
     * outgoing edge. Re-prompts (without advancing) when the value fails the
     * node's input_type validation.
     */
    /**
     * @param  array<int, array<string, mixed>>  $attachments  This turn's uploads.
     */
    private function processInputNode(BotFlow $flow, array $flowState, array $node, string $userInput, array $attachments = []): BotFlowAction
    {
        $variable = $node['data']['variable'] ?? 'input';
        $inputType = $node['data']['input_type'] ?? 'text';

        // File input: capture the accepted uploads instead of a text value, and
        // re-prompt when nothing acceptable was attached this turn.
        if ($inputType === 'file') {
            $accepted = $this->filterAcceptedFiles($attachments, $node['data']['accept'] ?? []);

            if ($accepted === []) {
                return new BotFlowAction(
                    BotFlowActionType::CollectInput,
                    [
                        'prompt' => $node['data']['error_message'] ?? $node['data']['prompt'] ?? '',
                        'variable' => $variable,
                        'input_type' => 'file',
                        'accept' => $node['data']['accept'] ?? [],
                        'invalid' => true,
                    ],
                    $flowState
                );
            }

            $variables = $flowState['variables'] ?? [];
            $variables[$variable] = $this->lightDescriptors($accepted);
            $flowState['variables'] = $variables;

            $edges = $flow->getEdgesFrom($node['id']);
            if (empty($edges)) {
                return $this->endFlow($flowState, 'resume_conversation');
            }

            return $this->advanceToNode($flow, $flowState, $edges[0]['target']);
        }

        $value = trim($userInput);

        if (! $this->isValidInput($inputType, $value)) {
            return new BotFlowAction(
                BotFlowActionType::CollectInput,
                [
                    'prompt' => $node['data']['error_message'] ?? $node['data']['prompt'] ?? '',
                    'variable' => $variable,
                    'input_type' => $inputType,
                    'invalid' => true,
                ],
                $flowState
            );
        }

        $variables = $flowState['variables'] ?? [];
        $variables[$variable] = $value;
        $flowState['variables'] = $variables;

        $edges = $flow->getEdgesFrom($node['id']);
        if (empty($edges)) {
            return $this->endFlow($flowState, 'resume_conversation');
        }

        return $this->advanceToNode($flow, $flowState, $edges[0]['target']);
    }

    /**
     * Terminate the flow and signal that a human should take over. Escalation is
     * persisted by the channel layer that consumes the HumanHandoff action.
     */
    private function humanHandoff(array $flowState, array $node): BotFlowAction
    {
        $flowState['completed'] = true;

        return new BotFlowAction(
            BotFlowActionType::HumanHandoff,
            [
                'message' => $node['data']['message'] ?? null,
                'reason' => $node['data']['reason'] ?? null,
                'notify' => $node['data']['notify'] ?? true,
            ],
            $flowState
        );
    }

    /**
     * Validate captured input against the node's declared type. An empty value
     * never passes — the node re-prompts until the user supplies something.
     */
    private function isValidInput(string $type, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return match ($type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'number' => is_numeric($value),
            'phone' => (bool) preg_match('/^[0-9+\-()\s]{7,}$/', $value),
            default => true,
        };
    }

    /**
     * Keep only attachments matching the node's `accept` tokens (kinds, MIME
     * prefixes, or extensions). An empty `accept` accepts everything.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @param  array<int, string>  $accept
     * @return array<int, array<string, mixed>>
     */
    private function filterAcceptedFiles(array $attachments, array $accept): array
    {
        if ($accept === []) {
            return array_values($attachments);
        }

        return array_values(array_filter(
            $attachments,
            fn ($file) => collect($accept)->contains(
                fn (string $token) => $this->anyFileMatchesType([$file], $token)
            )
        ));
    }

    private function processMessageNode(BotFlow $flow, array $flowState, array $node): BotFlowAction
    {
        // Send the message, then follow the edge to the next node
        $edges = $flow->getEdgesFrom($node['id']);

        if (empty($edges)) {
            // Message with no outgoing edge — end flow
            $flowState['completed'] = true;

            return new BotFlowAction(
                BotFlowActionType::SendMessage,
                ['message' => $node['data']['message'] ?? ''],
                $flowState
            );
        }

        // Return message action — caller should then advance to next node
        $nextNodeId = $edges[0]['target'];
        $flowState['current_node_id'] = $nextNodeId;
        $flowState['history'][] = $nextNodeId;

        return new BotFlowAction(
            BotFlowActionType::SendMessage,
            [
                'message' => $node['data']['message'] ?? '',
                'next_node_id' => $nextNodeId,
            ],
            $flowState
        );
    }

    private function processConnectorNode(BotFlow $flow, array $flowState, array $node): BotFlowAction
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

    private function endFlow(array $flowState, string $action): BotFlowAction
    {
        $flowState['completed'] = true;

        return new BotFlowAction(
            BotFlowActionType::End,
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

    /**
     * Resolve the file descriptor(s) a condition routes on: the node's named
     * `variable` if set, otherwise this turn's `_last_upload`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveConditionFiles(array $flowState, array $node): array
    {
        $variable = $node['data']['variable'] ?? '_last_upload';

        return $this->lightDescriptors($flowState['variables'][$variable] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    private function matchesFileCondition(string $matchType, string $pattern, array $files): bool
    {
        return match ($matchType) {
            'has_file' => $files !== [],
            'file_type_is' => $this->anyFileMatchesType($files, $pattern),
            default => false,
        };
    }

    /**
     * Whether any file matches a type token: a coarse kind (`image`, `document`,
     * `audio`), a MIME (prefix) match, or an extension/keyword on the filename.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    private function anyFileMatchesType(array $files, string $pattern): bool
    {
        $needle = mb_strtolower(trim($pattern));
        if ($needle === '') {
            return false;
        }

        foreach ($files as $file) {
            $kind = mb_strtolower((string) ($file['kind'] ?? ''));
            $mime = mb_strtolower((string) ($file['mime'] ?? ''));
            $name = mb_strtolower((string) ($file['original_name'] ?? ''));

            if ($kind === $needle
                || str_contains($mime, $needle)
                || str_ends_with($name, '.'.$needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a descriptor or list of descriptors into a list, dropping the
     * (potentially large) extracted_text so it never bloats the persisted
     * flow_state — agents read fresh extracted text from the turn's attachments.
     *
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function lightDescriptors($value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        // A single descriptor (associative) vs a list of them.
        $list = array_is_list($value) ? $value : [$value];

        return array_values(array_map(function ($descriptor) {
            if (! is_array($descriptor)) {
                return $descriptor;
            }
            unset($descriptor['extracted_text']);

            return $descriptor;
        }, $list));
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
