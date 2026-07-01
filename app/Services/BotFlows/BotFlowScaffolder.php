<?php

namespace App\Services\BotFlows;

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * Turns a natural-language bot description into a valid Bot Flow definition.
 *
 * The model only produces a small, constrained spec (roles + welcome + menu);
 * the graph itself is assembled deterministically here so the result is always
 * a valid, well-wired definition the author can then refine on the canvas.
 */
class BotFlowScaffolder
{
    private const SYSTEM = <<<'SYS'
        You design conversational customer-support bots.
        Given a description, respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary — using exactly this schema:
        {"welcome_message": string, "roles": string[], "collect": [{"prompt": string, "variable": string, "input_type": "text"|"email"|"number"|"phone"}] | null, "menu": {"message": string, "options": [{"label": string, "route_role": "knowledge"|"action"|"human"|null}]} | null}
        - roles: any of "triage" (routes the conversation), "knowledge" (answers from docs), "action" (runs tasks/tools). Include only the roles the bot actually needs.
        - collect: details to capture from the user up front (e.g. name, email) before the menu. Each needs a short prompt, a snake_case variable, and an input_type. Use null when there is nothing to collect. At most 3.
        - menu: a starting menu, or null if the bot should just talk. At most 4 options.
        - route_role: for a menu option, "knowledge" or "action" to hand off to that agent, "human" to escalate to a human agent, or null for a general option.
        SYS;

    private const CONVERSE_SYSTEM = <<<'SYS'
        You help a user design a conversational customer-support bot through chat.
        You are given the current flow spec and the conversation so far. Respond with ONLY a single minified JSON object — no markdown, no code fences, no commentary — using exactly this schema:
        {"reply": string, "spec": {"welcome_message": string, "roles": string[], "collect": [{"prompt": string, "variable": string, "input_type": "text"|"email"|"number"|"phone"}] | null, "menu": {"message": string, "options": [{"label": string, "route_role": "knowledge"|"action"|"human"|null}]} | null}}
        - reply: a short, friendly message to the user describing what you changed.
        - spec: the COMPLETE updated flow (not a diff) reflecting all changes requested so far. Keep prior content unless the user asked to change it.
        - roles: any of "triage", "knowledge", "action" — only those the bot needs.
        - collect: details to capture before the menu (max 3), each with a prompt, snake_case variable, and input_type; or null.
        - menu: a menu (max 4 options) or null. route_role is "knowledge", "action", "human", or null.
        SYS;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
    ) {}

    /**
     * @param  array{triage: array<int, array{id: string, name: string}>, knowledge: array<int, array{id: string, name: string}>, action: array<int, array{id: string, name: string}>}  $availableAgents
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function scaffold(string $description, array $availableAgents, ?User $user = null): array
    {
        return $this->assemble($this->extractSpec($description, $user), $availableAgents);
    }

    /**
     * One multi-turn chat step: refine the running spec from the conversation and
     * re-assemble. Stateless — the caller round-trips the spec each turn.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $currentSpec
     * @param  array{triage?: array, knowledge?: array, action?: array}  $availableAgents
     * @return array{reply: string, spec: array<string, mixed>, definition: array{nodes: array, edges: array}}
     */
    public function converse(array $messages, ?array $currentSpec, array $availableAgents, ?User $user = null): array
    {
        $refined = $this->refineSpec($messages, $currentSpec, $user);

        return [
            'reply' => $refined['reply'],
            'spec' => $refined['spec'],
            'definition' => $this->assemble($refined['spec'], $availableAgents),
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $currentSpec
     * @return array{reply: string, spec: array<string, mixed>}
     */
    private function refineSpec(array $messages, ?array $currentSpec, ?User $user): array
    {
        $model = $this->aiDefaults->model('flows');
        $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

        $spec = $currentSpec ? $this->normalizeSpec($currentSpec) : $this->defaultSpec();
        $prompt = 'Current flow spec:'.PHP_EOL.json_encode($spec).PHP_EOL.PHP_EOL
            .'Conversation:'.PHP_EOL.$this->buildTranscript($messages);

        try {
            $agent = new ChatAgent(instructions: self::CONVERSE_SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(Str::limit($prompt, 4000), provider: $provider, model: $model, timeout: (int) config('ai.request_timeout', 180));
            $decoded = $this->decodeJson((string) ($response->text ?? ''));

            if ($decoded === null) {
                return ['reply' => 'Sorry, I could not apply that. Try rephrasing.', 'spec' => $spec];
            }

            return [
                'reply' => (string) ($decoded['reply'] ?? 'Updated the flow.'),
                'spec' => isset($decoded['spec']) && is_array($decoded['spec'])
                    ? $this->normalizeSpec($decoded['spec'])
                    : $spec,
            ];
        } catch (\Throwable $e) {
            Log::warning('Bot flow converse: model call failed', ['error' => $e->getMessage()]);

            return ['reply' => 'Sorry, something went wrong. Try again.', 'spec' => $spec];
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function buildTranscript(array $messages): string
    {
        $lines = [];
        foreach (array_slice($messages, -12) as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                $lines[] = "{$role}: {$content}";
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return array{welcome_message: string, roles: array<int, string>, menu: array{message: string, options: array<int, array{label: string, route_role: ?string}>}|null}
     */
    private function extractSpec(string $description, ?User $user): array
    {
        $model = $this->aiDefaults->model('flows');
        $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

        try {
            $agent = new ChatAgent(instructions: self::SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(Str::limit($description, 2000), provider: $provider, model: $model, timeout: (int) config('ai.request_timeout', 180));

            return $this->parseSpec((string) ($response->text ?? ''));
        } catch (\Throwable $e) {
            Log::warning('Bot flow scaffold: model call failed', ['error' => $e->getMessage()]);

            return $this->defaultSpec();
        }
    }

    /**
     * @return array{welcome_message: string, roles: array<int, string>, menu: array{message: string, options: array<int, array{label: string, route_role: ?string}>}|null}
     */
    private function parseSpec(string $raw): array
    {
        $decoded = $this->decodeJson($raw);

        return $decoded === null ? $this->defaultSpec() : $this->normalizeSpec($decoded);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $json = trim($raw);
        $json = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $json);
        if (preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{welcome_message: string, roles: array<int, string>, collect: array<int, array{prompt: string, variable: string, input_type: string}>, menu: array{message: string, options: array<int, array{label: string, route_role: ?string}>}|null}
     */
    private function normalizeSpec(array $decoded): array
    {
        $roles = array_values(array_intersect(
            ['triage', 'knowledge', 'action'],
            array_map('strval', is_array($decoded['roles'] ?? null) ? $decoded['roles'] : []),
        ));

        return [
            'welcome_message' => (string) ($decoded['welcome_message'] ?? ''),
            'roles' => $roles ?: ['triage'],
            'collect' => $this->normalizeCollect($decoded['collect'] ?? null),
            'menu' => $this->normalizeMenu($decoded['menu'] ?? null),
        ];
    }

    /**
     * Normalize the up-front data-capture fields. Each becomes an input node;
     * the variable is slugified so the captured value has a safe, stable key.
     *
     * @return array<int, array{prompt: string, variable: string, input_type: string}>
     */
    private function normalizeCollect(mixed $collect): array
    {
        if (! is_array($collect)) {
            return [];
        }

        $fields = [];
        foreach (array_slice($collect, 0, 3) as $i => $field) {
            if (! is_array($field)) {
                continue;
            }

            $variable = trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower((string) ($field['variable'] ?? ''))), '_');
            if ($variable === '') {
                continue;
            }

            $type = $field['input_type'] ?? 'text';
            $fields[] = [
                'prompt' => (string) ($field['prompt'] ?? ('Please provide '.$variable.'.')),
                'variable' => $variable,
                'input_type' => in_array($type, ['text', 'email', 'number', 'phone'], true) ? $type : 'text',
            ];
        }

        return $fields;
    }

    /**
     * @return array{message: string, options: array<int, array{label: string, route_role: ?string}>}|null
     */
    private function normalizeMenu(mixed $menu): ?array
    {
        if (! is_array($menu) || ! is_array($menu['options'] ?? null)) {
            return null;
        }

        $options = [];
        foreach (array_slice($menu['options'], 0, 4) as $i => $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $role = $opt['route_role'] ?? null;
            $options[] = [
                'label' => (string) ($opt['label'] ?? ('Option '.($i + 1))),
                'route_role' => in_array($role, ['knowledge', 'action', 'human'], true) ? $role : null,
            ];
        }

        if ($options === []) {
            return null;
        }

        return ['message' => (string) ($menu['message'] ?? ''), 'options' => $options];
    }

    /**
     * @return array{welcome_message: string, roles: array<int, string>, collect: array<int, mixed>, menu: null}
     */
    private function defaultSpec(): array
    {
        return ['welcome_message' => '', 'roles' => ['triage'], 'collect' => [], 'menu' => null];
    }

    /**
     * Deterministically assemble a valid Bot Flow graph from a spec.
     *
     * @param  array{welcome_message?: string, roles?: array<int, string>, collect?: array<int, array{prompt?: string, variable?: string, input_type?: string}>, menu?: array{message?: string, options?: array<int, array{label?: string, route_role?: ?string}>}|null}  $spec
     * @param  array{triage?: array, knowledge?: array, action?: array}  $availableAgents
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function assemble(array $spec, array $availableAgents): array
    {
        $nodes = [];
        $edges = [];
        $edgeId = 0;
        $addEdge = function (string $source, string $target, ?string $handle = null) use (&$edges, &$edgeId): void {
            $edge = ['id' => 'e_'.$edgeId++, 'source' => $source, 'target' => $target];
            if ($handle !== null) {
                $edge['sourceHandle'] = $handle;
            }
            $edges[] = $edge;
        };

        $nodes[] = ['id' => 'start', 'type' => 'start', 'position' => ['x' => 250, 'y' => 0], 'data' => ['trigger' => 'conversation_start']];

        // Roster: one agent node per requested role, auto-bound to a real agent.
        $roles = array_values(array_intersect(['triage', 'knowledge', 'action'], $spec['roles'] ?? []));
        $rosterY = 0;
        foreach ($roles as $role) {
            $first = $availableAgents[$role][0] ?? null;
            $nodes[] = ['id' => 'agent_'.$role, 'type' => 'agent', 'position' => ['x' => 620, 'y' => $rosterY], 'data' => [
                'role' => $role,
                'agent_id' => $first['id'] ?? null,
                'agent_name' => $first['name'] ?? null,
            ]];
            $rosterY += 110;
        }

        $prev = 'start';
        $y = 130;

        $welcome = trim((string) ($spec['welcome_message'] ?? ''));
        if ($welcome !== '') {
            $nodes[] = ['id' => 'welcome', 'type' => 'message', 'position' => ['x' => 250, 'y' => $y], 'data' => ['message' => $welcome]];
            $addEdge($prev, 'welcome');
            $prev = 'welcome';
            $y += 130;
        }

        // Up-front data capture: a chain of input nodes gathered before the menu.
        foreach ($spec['collect'] ?? [] as $i => $field) {
            $nodeId = 'collect_'.$i;
            $nodes[] = ['id' => $nodeId, 'type' => 'input', 'position' => ['x' => 250, 'y' => $y], 'data' => [
                'prompt' => (string) ($field['prompt'] ?? ''),
                'variable' => (string) ($field['variable'] ?? ('field_'.$i)),
                'input_type' => $field['input_type'] ?? 'text',
            ]];
            $addEdge($prev, $nodeId);
            $prev = $nodeId;
            $y += 130;
        }

        $options = is_array($spec['menu']['options'] ?? null) ? array_slice($spec['menu']['options'], 0, 4) : [];

        if ($options !== []) {
            $menuOptions = [];
            foreach ($options as $i => $opt) {
                $menuOptions[] = ['id' => 'option_'.$i, 'label' => (string) ($opt['label'] ?? ('Option '.($i + 1)))];
            }
            $nodes[] = ['id' => 'menu', 'type' => 'menu', 'position' => ['x' => 250, 'y' => $y], 'data' => ['message' => (string) ($spec['menu']['message'] ?? ''), 'options' => $menuOptions]];
            $addEdge($prev, 'menu');
            $y += 130;

            foreach ($options as $i => $opt) {
                $role = $opt['route_role'] ?? null;
                if (in_array($role, ['knowledge', 'action'], true)) {
                    $nodes[] = ['id' => 'handoff_'.$i, 'type' => 'agent_handoff', 'position' => ['x' => 80 + $i * 190, 'y' => $y], 'data' => ['target_agent' => $role]];
                    $addEdge('menu', 'handoff_'.$i, 'option_'.$i);
                } elseif ($role === 'human') {
                    $nodes[] = ['id' => 'human_'.$i, 'type' => 'human_handoff', 'position' => ['x' => 80 + $i * 190, 'y' => $y], 'data' => ['notify' => true]];
                    $addEdge('menu', 'human_'.$i, 'option_'.$i);
                } else {
                    $nodes[] = ['id' => 'end_'.$i, 'type' => 'end', 'position' => ['x' => 80 + $i * 190, 'y' => $y], 'data' => ['action' => 'resume_conversation']];
                    $addEdge('menu', 'end_'.$i, 'option_'.$i);
                }
            }
        } else {
            $nodes[] = ['id' => 'end', 'type' => 'end', 'position' => ['x' => 250, 'y' => $y], 'data' => ['action' => 'resume_conversation']];
            $addEdge($prev, 'end');
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
