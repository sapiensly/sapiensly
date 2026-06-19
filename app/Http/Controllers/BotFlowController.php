<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\BotFlowStatus;
use App\Enums\Visibility;
use App\Http\Requests\BotFlow\StoreBotFlowRequest;
use App\Http\Requests\BotFlow\UpdateBotFlowRequest;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\Chatbot;
use App\Models\KnowledgeBase;
use App\Models\Tool;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AiProviderService;
use App\Services\BotFlows\BotFlowScaffolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BotFlowController extends Controller
{
    public function __construct(
        private readonly AiProviderService $aiProviderService,
    ) {}

    public function globalIndex(Request $request): Response
    {
        $flows = BotFlow::query()
            ->forAccountContext($request->user())
            ->with('agent:id,name,type')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return Inertia::render('bot-flows/GlobalIndex', [
            'flows' => $flows,
        ]);
    }

    public function globalCreate(Request $request): Response
    {
        return Inertia::render('bot-flows/Edit', [
            'agent' => null,
            'flow' => null,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    public function globalStore(StoreBotFlowRequest $request): RedirectResponse
    {
        $flow = BotFlow::create([
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

    public function globalEdit(Request $request, BotFlow $flow): Response
    {
        return Inertia::render('bot-flows/Edit', [
            'agent' => null,
            'flow' => $flow,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    /**
     * Edit the Bot Flow owned by an AI Bot, creating a blank one on first open.
     */
    public function editForChatbot(Request $request, Chatbot $chatbot): Response
    {
        $this->authorize('update', $chatbot);

        $flow = $chatbot->botFlow ?? BotFlow::blankForChatbot($chatbot);

        return Inertia::render('bot-flows/Edit', [
            'agent' => null,
            'flow' => $flow,
            'chatbot' => $chatbot->only(['id', 'name']),
            ...$this->getEditorProps($request->user()),
        ]);
    }

    /**
     * Edit the Bot Flow owned by a WhatsApp connection (visual builder).
     */
    public function editForWhatsApp(Request $request, WhatsAppConnection $whatsappConnection): Response
    {
        $this->authorize('update', $whatsappConnection);

        $flow = $whatsappConnection->botFlow ?? BotFlow::blankForWhatsApp($whatsappConnection);

        return Inertia::render('bot-flows/Edit', [
            'agent' => null,
            'flow' => $flow,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    /**
     * Generate a starter Bot Flow definition from a natural-language description.
     * Returns the definition for the canvas to load; the author saves explicitly.
     */
    public function scaffold(Request $request, Chatbot $chatbot, BotFlowScaffolder $scaffolder): JsonResponse
    {
        $this->authorize('update', $chatbot);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $definition = $scaffolder->scaffold(
            $validated['description'],
            $this->getAvailableAgents($request->user()),
            $request->user(),
        );

        return response()->json(['definition' => $definition]);
    }

    /**
     * One conversational turn editing the Bot Flow: refine the running spec from
     * the chat and return the reply, the updated spec, and the new definition.
     */
    public function converse(Request $request, Chatbot $chatbot, BotFlowScaffolder $scaffolder): JsonResponse
    {
        $this->authorize('update', $chatbot);

        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:2000'],
            'spec' => ['nullable', 'array'],
        ]);

        $result = $scaffolder->converse(
            $validated['messages'],
            $validated['spec'] ?? null,
            $this->getAvailableAgents($request->user()),
            $request->user(),
        );

        return response()->json($result);
    }

    public function globalUpdate(UpdateBotFlowRequest $request, BotFlow $flow): RedirectResponse
    {
        $flow->update($request->validated());

        return back();
    }

    public function globalDestroy(BotFlow $flow): RedirectResponse
    {
        $flow->delete();

        return to_route('flows.index');
    }

    public function index(Agent $agent): Response
    {
        $flows = $agent->flows()
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('bot-flows/Index', [
            'agent' => $agent,
            'flows' => $flows,
        ]);
    }

    public function create(Request $request, Agent $agent): Response
    {
        return Inertia::render('bot-flows/Edit', [
            'agent' => $agent,
            'flow' => null,
            ...$this->getEditorProps($request->user()),
        ]);
    }

    public function store(StoreBotFlowRequest $request, Agent $agent): RedirectResponse
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

    public function edit(Request $request, Agent $agent, BotFlow $flow): Response
    {
        return Inertia::render('bot-flows/Edit', [
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
            'knowledge_base_ids.*' => ['string', 'exists:tenant.knowledge_bases,id'],
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
            $agent->syncKnowledgeBases($validated['knowledge_base_ids']);
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

    public function update(UpdateBotFlowRequest $request, Agent $agent, BotFlow $flow): RedirectResponse
    {
        $flow->update($request->validated());

        return back();
    }

    public function destroy(Agent $agent, BotFlow $flow): RedirectResponse
    {
        $flow->delete();

        return to_route('agents.flows.index', $agent);
    }

    public function activate(Agent $agent, BotFlow $flow): RedirectResponse
    {
        // Deactivate other active flows for this agent
        $agent->flows()
            ->where('id', '!=', $flow->id)
            ->where('status', BotFlowStatus::Active)
            ->update(['status' => BotFlowStatus::Inactive]);

        $flow->update(['status' => BotFlowStatus::Active]);

        return back();
    }
}
