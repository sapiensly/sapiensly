<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\MessageRole;
use App\Enums\Visibility;
use App\Http\Requests\Agent\StoreAgentRequest;
use App\Http\Requests\Agent\UpdateAgentRequest;
use App\Jobs\ProcessAgentChat;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\KnowledgeBase;
use App\Models\Tool;
use App\Services\AiProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function __construct(
        private AiProviderService $aiProviderService,
    ) {}

    public function index(Request $request): Response
    {
        $typeFilter = $request->query('type');

        $query = Agent::query()
            ->forAccountContext($request->user())
            ->with('team:id,name')
            ->withCount(['knowledgeBases', 'tools'])
            ->latest();

        if ($typeFilter && in_array($typeFilter, array_column(AgentType::cases(), 'value'))) {
            $query->where('type', $typeFilter);
        }

        $agents = $query->paginate(12)->withQueryString();

        $agentsByType = Agent::query()
            ->forAccountContext($request->user())
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return Inertia::render('standalone-agents/Index', [
            'agents' => $agents,
            'agentsByType' => $agentsByType,
            'currentType' => $typeFilter,
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $request->query('type');

        return Inertia::render('standalone-agents/Create', [
            'selectedType' => $type,
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableModels' => $this->aiProviderService->getAvailableModels($request->user()),
            'recommendedModels' => $this->aiProviderService->getRecommendedModels(),
            'knowledgeBases' => KnowledgeBase::forAccountContext($request->user())->where('status', 'ready')->get(['id', 'name']),
            'tools' => Tool::forAccountContext($request->user())->where('status', 'active')->get(['id', 'name', 'type']),
        ]);
    }

    public function store(StoreAgentRequest $request): RedirectResponse
    {
        $user = $request->user();

        $agent = Agent::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'agent_team_id' => null,
            'type' => $request->type,
            'name' => $request->name,
            'description' => $request->description,
            'keywords' => $request->keywords ?? [],
            'status' => AgentStatus::Draft,
            'prompt_template' => $request->prompt_template,
            'model' => $request->model,
            'config' => $request->config ?? [],
        ]);

        if ($request->has('knowledge_base_ids')) {
            $agent->knowledgeBases()->sync($request->knowledge_base_ids);
        }

        if ($request->has('tool_ids')) {
            $agent->tools()->sync($request->tool_ids);
        }

        return to_route('agents.show', $agent);
    }

    public function show(Request $request, Agent $agent): Response
    {
        if (! $agent->isVisibleTo($request->user())) {
            abort(403);
        }

        return Inertia::render('standalone-agents/Show', [
            'agent' => $agent->load(['knowledgeBases', 'tools']),
        ]);
    }

    public function edit(Request $request, Agent $agent): Response
    {
        if (! $agent->isOwnedBy($request->user())) {
            abort(403);
        }

        $activeFlow = $agent->activeFlow();

        return Inertia::render('standalone-agents/Edit', [
            'agent' => $agent->load(['knowledgeBases', 'tools']),
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableModels' => $this->aiProviderService->getAvailableModels($request->user()),
            'recommendedModels' => $this->aiProviderService->getRecommendedModels(),
            'knowledgeBases' => KnowledgeBase::forAccountContext($request->user())->where('status', 'ready')->get(['id', 'name']),
            'tools' => Tool::forAccountContext($request->user())->where('status', 'active')->get(['id', 'name', 'type']),
            'activeFlow' => $activeFlow,
        ]);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): RedirectResponse
    {
        $agent->update([
            'name' => $request->name,
            'description' => $request->description,
            'keywords' => $request->keywords ?? [],
            'status' => $request->status ?? $agent->status,
            'prompt_template' => $request->prompt_template,
            'model' => $request->model,
            'config' => $request->config ?? $agent->config,
        ]);

        if ($request->has('knowledge_base_ids')) {
            $agent->knowledgeBases()->sync($request->knowledge_base_ids ?? []);
        }

        if ($request->has('tool_ids')) {
            $agent->tools()->sync($request->tool_ids ?? []);
        }

        return to_route('agents.show', $agent);
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        if (! $agent->isOwnedBy($request->user())) {
            abort(403);
        }

        $agent->delete();

        return to_route('agents.index');
    }

    public function duplicate(Request $request, Agent $agent): RedirectResponse
    {
        if (! $agent->isOwnedBy($request->user())) {
            abort(403);
        }

        $newAgent = $agent->replicate();
        $newAgent->name = $agent->name.' (Copy)';
        $newAgent->status = AgentStatus::Draft;
        $newAgent->save();

        $newAgent->knowledgeBases()->sync($agent->knowledgeBases->pluck('id'));
        $newAgent->tools()->sync($agent->tools->pluck('id'));

        return to_route('agents.show', $newAgent);
    }

    public function chat(Request $request, Agent $agent): Response
    {
        if (! $agent->isVisibleTo($request->user())) {
            abort(403);
        }

        // Get or create a conversation for this agent
        $conversation = Conversation::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'agent_id' => $agent->id,
            ],
            [
                'title' => "Chat with {$agent->name}",
            ]
        );

        return Inertia::render('standalone-agents/Chat', [
            'agent' => $agent,
            'conversation' => $conversation->load('messages'),
        ]);
    }

    public function sendMessage(Request $request, Agent $agent): RedirectResponse
    {
        if (! $agent->isVisibleTo($request->user())) {
            abort(403);
        }

        $request->validate([
            'message' => 'required|string|max:10000',
        ]);

        // Get or create conversation
        $conversation = Conversation::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'agent_id' => $agent->id,
            ],
            [
                'title' => "Chat with {$agent->name}",
            ]
        );

        // Save user message
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $request->message,
        ]);

        // Dispatch job to process response via WebSocket streaming
        ProcessAgentChat::dispatch($agent, $conversation);

        return back();
    }

    public function newConversation(Request $request, Agent $agent): RedirectResponse
    {
        if (! $agent->isVisibleTo($request->user())) {
            abort(403);
        }

        // Delete existing conversation for this agent
        Conversation::where('user_id', $request->user()->id)
            ->where('agent_id', $agent->id)
            ->delete();

        return to_route('agents.chat', $agent);
    }
}
