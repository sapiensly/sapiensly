<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\FlowStatus;
use App\Enums\Visibility;
use App\Http\Requests\Flow\StoreFlowRequest;
use App\Http\Requests\Flow\UpdateFlowRequest;
use App\Models\Agent;
use App\Models\Flow;
use App\Models\KnowledgeBase;
use App\Models\Tool;
use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FlowController extends Controller
{
    public function __construct(
        private readonly AiProviderService $aiProviderService,
    ) {}

    public function globalIndex(Request $request): Response
    {
        $flows = Flow::query()
            ->forAccountContext($request->user())
            ->with('agent:id,name,type')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return Inertia::render('flows/GlobalIndex', [
            'flows' => $flows,
        ]);
    }

    public function globalCreate(Request $request): Response
    {
        return Inertia::render('flows/Edit', [
            'agent' => null,
            'flow' => null,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    public function globalStore(StoreFlowRequest $request): RedirectResponse
    {
        $flow = Flow::create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'visibility' => $request->user()->organization_id
                ? Visibility::Organization
                : Visibility::Private,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'definition' => $request->validated('definition'),
        ]);

        return to_route('flows.edit', $flow);
    }

    public function globalEdit(Request $request, Flow $flow): Response
    {
        return Inertia::render('flows/Edit', [
            'agent' => null,
            'flow' => $flow,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    public function globalUpdate(UpdateFlowRequest $request, Flow $flow): RedirectResponse
    {
        $flow->update($request->validated());

        return back();
    }

    public function globalDestroy(Flow $flow): RedirectResponse
    {
        $flow->delete();

        return to_route('flows.index');
    }

    public function index(Agent $agent): Response
    {
        $flows = $agent->flows()
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('flows/Index', [
            'agent' => $agent,
            'flows' => $flows,
        ]);
    }

    public function create(Request $request, Agent $agent): Response
    {
        return Inertia::render('flows/Edit', [
            'agent' => $agent,
            'flow' => null,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    public function store(StoreFlowRequest $request, Agent $agent): RedirectResponse
    {
        $flow = $agent->flows()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'definition' => $request->validated('definition'),
        ]);

        return to_route('agents.flows.edit', [$agent, $flow]);
    }

    public function edit(Request $request, Agent $agent, Flow $flow): Response
    {
        return Inertia::render('flows/Edit', [
            'agent' => $agent,
            'flow' => $flow,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    /**
     * Create an agent from the flow editor (returns JSON).
     */
    public function createAgentForLayer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(AgentType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'model' => ['required', 'string', 'max:100'],
            'prompt_template' => ['nullable', 'string'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'config' => ['nullable', 'array'],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['string', 'exists:knowledge_bases,id'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
        ]);

        $user = $request->user();

        $agent = Agent::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'agent_team_id' => null,
            'type' => $validated['type'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'keywords' => $validated['keywords'] ?? [],
            'status' => AgentStatus::Draft,
            'prompt_template' => $validated['prompt_template'] ?? null,
            'model' => $validated['model'],
            'config' => $validated['config'] ?? [],
        ]);

        if (! empty($validated['knowledge_base_ids'])) {
            $agent->knowledgeBases()->sync($validated['knowledge_base_ids']);
        }

        if (! empty($validated['tool_ids'])) {
            $agent->tools()->sync($validated['tool_ids']);
        }

        return response()->json([
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'model' => $agent->model,
                'type' => $agent->type->value,
            ],
        ]);
    }

    /**
     * Get available agents grouped by type for the current account context.
     *
     * @return array<string, array<int, array{id: string, name: string, model: string}>>
     */
    private function getAvailableAgents(User $user): array
    {
        $agents = Agent::query()
            ->forAccountContext($user)
            ->get(['id', 'name', 'type', 'model'])
            ->groupBy(fn (Agent $a) => $a->type->value);

        $shape = fn ($collection) => $collection->map(fn (Agent $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'model' => $a->model,
        ])->values()->all();

        return [
            'triage' => $shape($agents->get('triage', collect())),
            'knowledge' => $shape($agents->get('knowledge', collect())),
            'action' => $shape($agents->get('action', collect())),
        ];
    }

    /**
     * Get common editor props (models, agents, knowledge bases, tools).
     *
     * @return array<string, mixed>
     */
    private function getEditorProps(User $user): array
    {
        return [
            'availableModels' => $this->aiProviderService->getAvailableModels($user),
            'availableAgents' => $this->getAvailableAgents($user),
            'knowledgeBases' => KnowledgeBase::forAccountContext($user)->where('status', 'ready')->get(['id', 'name']),
            'tools' => Tool::forAccountContext($user)->where('status', 'active')->get(['id', 'name', 'type']),
        ];
    }

    public function update(UpdateFlowRequest $request, Agent $agent, Flow $flow): RedirectResponse
    {
        $flow->update($request->validated());

        return back();
    }

    public function destroy(Agent $agent, Flow $flow): RedirectResponse
    {
        $flow->delete();

        return to_route('agents.flows.index', $agent);
    }

    public function activate(Agent $agent, Flow $flow): RedirectResponse
    {
        // Deactivate other active flows for this agent
        $agent->flows()
            ->where('id', '!=', $flow->id)
            ->where('status', FlowStatus::Active)
            ->update(['status' => FlowStatus::Inactive]);

        $flow->update(['status' => FlowStatus::Active]);

        return back();
    }
}
