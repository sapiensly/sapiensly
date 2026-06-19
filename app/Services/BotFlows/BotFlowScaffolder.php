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
        {"welcome_message": string, "roles": string[], "menu": {"message": string, "options": [{"label": string, "route_role": string|null}]} | null}
        - roles: any of "triage" (routes the conversation), "knowledge" (answers from docs), "action" (runs tasks/tools). Include only the roles the bot actually needs.
        - menu: a starting menu, or null if the bot should just talk. At most 4 options.
        - route_role: for a menu option, one of "knowledge" or "action" to hand off there, or null for a general option.
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
     * @return array{welcome_message: string, roles: array<int, string>, menu: array{message: string, options: array<int, array{label: string, route_role: ?string}>}|null}
     */
    private function extractSpec(string $description, ?User $user): array
    {
        $model = $this->aiDefaults->model('flows');
        $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

        try {
            $agent = new ChatAgent(instructions: self::SYSTEM, messages: [], tools: []);
            $response = $agent->prompt(Str::limit($description, 2000), provider: $provider, model: $model);

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
        $json = trim($raw);
        $json = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $json);
        if (preg_match('/\{.*\}/s', $json, $m)) {
            $json = $m[0];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return $this->defaultSpec();
        }

        $roles = array_values(array_intersect(
            ['triage', 'knowledge', 'action'],
            array_map('strval', is_array($decoded['roles'] ?? null) ? $decoded['roles'] : []),
        ));

        return [
            'welcome_message' => (string) ($decoded['welcome_message'] ?? ''),
            'roles' => $roles ?: ['triage'],
            'menu' => $this->normalizeMenu($decoded['menu'] ?? null),
        ];
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
                'route_role' => in_array($role, ['knowledge', 'action'], true) ? $role : null,
            ];
        }

        if ($options === []) {
            return null;
        }

        return ['message' => (string) ($menu['message'] ?? ''), 'options' => $options];
    }

    /**
     * @return array{welcome_message: string, roles: array<int, string>, menu: null}
     */
    private function defaultSpec(): array
    {
        return ['welcome_message' => '', 'roles' => ['triage'], 'menu' => null];
    }

    /**
     * Deterministically assemble a valid Bot Flow graph from a spec.
     *
     * @param  array{welcome_message?: string, roles?: array<int, string>, menu?: array{message?: string, options?: array<int, array{label?: string, route_role?: ?string}>}|null}  $spec
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
