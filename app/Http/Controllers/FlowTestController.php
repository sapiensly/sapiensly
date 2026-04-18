<?php

namespace App\Http\Controllers;

use App\Enums\FlowActionType;
use App\Models\Flow;
use App\Services\FlowAction;
use App\Services\FlowExecutorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlowTestController extends Controller
{
    public function __construct(
        private readonly FlowExecutorService $executor,
    ) {}

    /**
     * Start a test session for a flow. Returns initial state and messages.
     */
    public function start(Request $request, Flow $flow): JsonResponse
    {
        $this->authorize('view', $flow);

        $startNode = $flow->getStartNode();
        if (! $startNode) {
            return response()->json([
                'error' => 'Flow has no start node.',
            ], 422);
        }

        $state = $this->executor->initializeFlow($flow);

        // Drive the flow from the start node forward (no user input yet)
        $messages = [];
        $action = $this->executor->processInput($flow, $state, '');
        $state = $this->collectMessages($flow, $action, $messages);

        return response()->json([
            'state' => $state,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message to a running flow test session.
     */
    public function send(Request $request, Flow $flow): JsonResponse
    {
        $this->authorize('view', $flow);

        $validated = $request->validate([
            'state' => ['required', 'array'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        if ($validated['state']['completed'] ?? false) {
            return response()->json([
                'state' => $validated['state'],
                'messages' => [
                    ['role' => 'assistant', 'content' => '[Flow already completed. Reset to start over.]'],
                ],
            ]);
        }

        $messages = [];
        $action = $this->executor->processInput($flow, $validated['state'], $validated['message']);
        $state = $this->collectMessages($flow, $action, $messages);

        return response()->json([
            'state' => $state,
            'messages' => $messages,
        ]);
    }

    /**
     * Walk through SendMessage actions until we hit one that requires user input.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    private function collectMessages(Flow $flow, FlowAction $action, array &$messages): array
    {
        $state = $action->updatedState;
        $iterations = 0;

        while ($iterations++ < 50) {
            switch ($action->type) {
                case FlowActionType::SendMessage:
                    $messages[] = ['role' => 'assistant', 'content' => $action->data['message'] ?? ''];

                    if (! ($state['completed'] ?? false) && isset($action->data['next_node_id'])) {
                        $action = $this->executor->processInput($flow, $state, '');
                        $state = $action->updatedState;

                        continue 2;
                    }

                    return $state;

                case FlowActionType::ShowMenu:
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $action->data['message'] ?? '',
                        'options' => array_values(array_map(
                            fn ($opt) => [
                                'id' => $opt['id'] ?? '',
                                'label' => $opt['label'] ?? '',
                            ],
                            $action->data['options'] ?? [],
                        )),
                    ];

                    return $state;

                case FlowActionType::AgentHandoff:
                    $target = $action->data['target_agent'] ?? 'agent';
                    $handoffMsg = $action->data['message'] ?? "[Handoff to {$target}]";
                    $messages[] = ['role' => 'assistant', 'content' => $handoffMsg];

                    return $state;

                case FlowActionType::AwaitLlmClassification:
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => '[LLM classification step — not executed in test mode. Use exact/contains/regex match to test condition nodes.]',
                    ];
                    $state['completed'] = true;

                    return $state;

                case FlowActionType::End:
                    $messages[] = ['role' => 'assistant', 'content' => '[Flow ended]'];

                    return $state;
            }

            return $state;
        }

        return $state;
    }
}
