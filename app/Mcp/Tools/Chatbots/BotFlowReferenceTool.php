<?php

namespace App\Mcp\Tools\Chatbots;

use App\Mcp\Tools\SapiensTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Authoring reference for bot flows: every node type, its fields and valid values, the edge structure, and a worked example. Read this before designing or editing a flow with update_bot_flow. Pass `node_type` to drill into one node.')]
class BotFlowReferenceTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $reference = $this->reference();

        $nodeType = trim((string) $request->get('node_type', ''));
        if ($nodeType !== '') {
            $node = collect($reference['node_types'])->firstWhere('type', $nodeType);
            if ($node === null) {
                return Response::json([
                    'error' => "Unknown node type '{$nodeType}'.",
                    'node_types' => collect($reference['node_types'])->pluck('type')->all(),
                ]);
            }

            return Response::json(['node_type' => $node]);
        }

        return Response::json($reference);
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(): array
    {
        return [
            'overview' => 'A bot flow is a directed graph of nodes and edges. Exactly one `start` node is the entry point; the engine walks edges, handling user input at the interactive nodes (menu, condition, input) and terminating at `agent_handoff`, `human_handoff`, or `end`. Persist a definition with update_bot_flow (it is validated) and step through it with test_bot_flow.',
            'definition_shape' => [
                'nodes' => 'array of node objects (see node_types)',
                'edges' => 'array of edge objects (see edges)',
                'viewport' => 'optional {x, y, zoom} — canvas position only, ignored at runtime',
            ],
            'node_common' => [
                'id' => 'string — unique within the flow; edges reference it',
                'type' => 'one of the node_types below',
                'position' => '{x, y} — canvas coordinates (authoring only)',
                'data' => 'type-specific config (see each node_type)',
            ],
            'edges' => [
                'shape' => ['id' => 'string (unique)', 'source' => 'source node id', 'target' => 'target node id', 'sourceHandle' => 'optional — selects which output of a multi-output node this edge leaves from', 'label' => 'optional display label'],
                'source_handle' => "Single-output nodes (start, message, input) use the first/only outgoing edge — no sourceHandle needed. Multi-output nodes route by sourceHandle: a `menu` edge's sourceHandle is the chosen option's id; a `condition` edge's sourceHandle is the matched rule's id, or the literal 'default' for the no-match fallback.",
            ],
            'variables' => "An `input` node stores the captured value in the flow state's `variables` bag, keyed by the node's `variable` field. Variables persist across turns for the rest of the conversation.",
            'target_agent' => "An `agent_handoff` node's `target_agent` is a fixed ROLE SLUG — one of `knowledge`, `action`, or `triage_llm` — NOT an account agent id. It is resolved against the flow's roster: the `agent` nodes bind real account agents (via `agent_id`) to the `triage` / `knowledge` / `action` roles. `triage_llm` is the built-in fallback when no roster agent applies. To use a specific account agent, add an `agent` node with that agent_id and the matching role.",
            'editor_only_fields' => "An `agent_handoff` node from the visual editor may also carry `mode` ('agent' | 'multi_agent') and `layers` (triage/knowledge/tools agent picks). These are EDITOR-ONLY presentation state — the runtime ignores them and routes purely by `target_agent` + the `agent` roster nodes. When authoring via update_bot_flow, set `target_agent` (and add `agent` roster nodes); you may leave `mode`/`layers` out. Don't infer the bot's real agent from them when reading a flow.",
            'node_types' => [
                [
                    'type' => 'start',
                    'purpose' => 'Entry point. Exactly one per flow.',
                    'fields' => [
                        'trigger' => "'conversation_start' (default) | 'keyword' | 'always'",
                        'keywords' => 'string[] — required when trigger is keyword; the flow activates when the user message contains one',
                    ],
                    'edges_out' => 'one (to the first node)',
                    'example' => ['id' => 'start', 'type' => 'start', 'position' => ['x' => 250, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                ],
                [
                    'type' => 'message',
                    'purpose' => 'Send a message, then continue to the next node.',
                    'fields' => ['message' => 'string'],
                    'edges_out' => 'one (omit it to end the flow after sending)',
                    'example' => ['id' => 'welcome', 'type' => 'message', 'position' => ['x' => 250, 'y' => 130], 'data' => ['message' => 'Hi! How can I help?']],
                ],
                [
                    'type' => 'menu',
                    'purpose' => 'Show options and wait for the user to pick one. Matches by 1-based index or option label (exact, then partial).',
                    'fields' => [
                        'message' => 'string — the prompt',
                        'options' => 'array of {id, label, value?} — at least one',
                    ],
                    'edges_out' => "one per option, each with sourceHandle = that option's id",
                    'example' => ['id' => 'menu', 'type' => 'menu', 'position' => ['x' => 250, 'y' => 260], 'data' => ['message' => 'What do you need?', 'options' => [['id' => 'o1', 'label' => 'Track order'], ['id' => 'o2', 'label' => 'Talk to a human']]]],
                ],
                [
                    'type' => 'condition',
                    'purpose' => 'Branch on the user input.',
                    'fields' => [
                        'match_type' => "'exact' | 'contains' | 'regex' | 'llm_classification'",
                        'rules' => 'array of {id, pattern, label?}',
                    ],
                    'edges_out' => "one per rule (sourceHandle = rule id), plus an optional sourceHandle = 'default' edge for the no-match case",
                    'example' => ['id' => 'cond', 'type' => 'condition', 'position' => ['x' => 250, 'y' => 390], 'data' => ['match_type' => 'contains', 'rules' => [['id' => 'r_yes', 'pattern' => 'yes', 'label' => 'Yes'], ['id' => 'r_no', 'pattern' => 'no', 'label' => 'No']]]],
                ],
                [
                    'type' => 'input',
                    'purpose' => 'Ask a question and capture the reply into a flow variable. Re-prompts until the reply passes input_type validation.',
                    'fields' => [
                        'prompt' => 'string — the question shown to the user',
                        'variable' => 'string (required) — the captured value is stored under flow variables[variable]',
                        'input_type' => "'text' (default) | 'email' | 'number' | 'phone' — validation applied to the reply",
                        'error_message' => 'string — shown on invalid input; falls back to prompt',
                    ],
                    'edges_out' => 'one (followed once a valid value is captured)',
                    'example' => ['id' => 'ask_email', 'type' => 'input', 'position' => ['x' => 250, 'y' => 520], 'data' => ['prompt' => "What's your email?", 'variable' => 'email', 'input_type' => 'email', 'error_message' => 'Please enter a valid email.']],
                ],
                [
                    'type' => 'agent',
                    'purpose' => 'Declarative roster member — binds a real account agent to a role. Not executed inline; it defines who agent_handoff can route to. Add one per role you intend to hand off to.',
                    'fields' => [
                        'role' => "'triage' | 'knowledge' | 'action' (required)",
                        'agent_id' => 'string | null — the account agent id (null in a draft; the roster skips unassigned roles)',
                        'agent_name' => 'string — optional display name',
                    ],
                    'edges_out' => 'none required',
                    'example' => ['id' => 'agent_knowledge', 'type' => 'agent', 'position' => ['x' => 620, 'y' => 0], 'data' => ['role' => 'knowledge', 'agent_id' => null]],
                ],
                [
                    'type' => 'agent_handoff',
                    'purpose' => 'Hand the conversation to a roster role. Ends the scripted flow and runs the matching agent.',
                    'fields' => [
                        'target_agent' => "'knowledge' | 'action' | 'triage_llm' — a role slug (see target_agent note above), NOT an agent id",
                        'context' => 'string — optional task/query passed to the agent; defaults to the last user message',
                        'message' => 'string — optional message shown before handing off',
                    ],
                    'edges_out' => 'none (terminal)',
                    'example' => ['id' => 'to_knowledge', 'type' => 'agent_handoff', 'position' => ['x' => 100, 'y' => 650], 'data' => ['target_agent' => 'knowledge']],
                ],
                [
                    'type' => 'human_handoff',
                    'purpose' => 'End the bot and escalate the conversation to a human agent. The channel layer flags the conversation for human takeover (and optionally notifies the team).',
                    'fields' => [
                        'message' => 'string — optional notice shown to the user before escalating',
                        'reason' => 'string — optional internal note describing the escalation',
                        'notify' => 'boolean — whether to notify the team (default true)',
                    ],
                    'edges_out' => 'none (terminal)',
                    'example' => ['id' => 'to_human', 'type' => 'human_handoff', 'position' => ['x' => 400, 'y' => 650], 'data' => ['message' => 'Connecting you to a human agent…', 'reason' => 'requested human', 'notify' => true]],
                ],
                [
                    'type' => 'connector',
                    'purpose' => 'Jump to another node — typically to loop back to the start or a menu.',
                    'fields' => [
                        'target_node_id' => "string — '__start__' to go to the start node, or the id of a menu node",
                        'target_label' => 'string — optional display label',
                    ],
                    'edges_out' => 'none (the jump replaces the edge)',
                    'example' => ['id' => 'loop', 'type' => 'connector', 'position' => ['x' => 250, 'y' => 650], 'data' => ['target_node_id' => '__start__']],
                ],
                [
                    'type' => 'end',
                    'purpose' => 'Terminate the flow.',
                    'fields' => [
                        'action' => "'resume_conversation' (hand back to normal chat) | 'close_conversation'",
                        'message' => 'string — optional farewell',
                    ],
                    'edges_out' => 'none (terminal)',
                    'example' => ['id' => 'done', 'type' => 'end', 'position' => ['x' => 250, 'y' => 780], 'data' => ['action' => 'resume_conversation']],
                ],
            ],
            'example_flow' => [
                'nodes' => [
                    ['id' => 'start', 'type' => 'start', 'position' => ['x' => 250, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                    ['id' => 'menu', 'type' => 'menu', 'position' => ['x' => 250, 'y' => 130], 'data' => ['message' => 'How can I help?', 'options' => [['id' => 'o1', 'label' => 'Track my order'], ['id' => 'o2', 'label' => 'Talk to a human']]]],
                    ['id' => 'ask_order', 'type' => 'input', 'position' => ['x' => 80, 'y' => 280], 'data' => ['prompt' => "What's your order number?", 'variable' => 'order_number', 'input_type' => 'text']],
                    ['id' => 'to_action', 'type' => 'agent_handoff', 'position' => ['x' => 80, 'y' => 420], 'data' => ['target_agent' => 'action', 'context' => 'Look up the order status.']],
                    ['id' => 'to_human', 'type' => 'human_handoff', 'position' => ['x' => 420, 'y' => 280], 'data' => ['message' => 'Connecting you to a human…', 'notify' => true]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'start', 'target' => 'menu'],
                    ['id' => 'e2', 'source' => 'menu', 'target' => 'ask_order', 'sourceHandle' => 'o1'],
                    ['id' => 'e3', 'source' => 'menu', 'target' => 'to_human', 'sourceHandle' => 'o2'],
                    ['id' => 'e4', 'source' => 'ask_order', 'target' => 'to_action'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'node_type' => $schema->string()->description('Optional — a single node type to drill into (e.g. "input"). Omit to get the full reference.'),
        ];
    }
}
