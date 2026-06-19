<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\Visibility;
use App\Http\Requests\Agent\StoreAgentRequest;
use App\Http\Requests\Agent\UpdateAgentRequest;
use App\Models\Agent;
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
            ->withCount(['knowledgeBaseLinks as knowledge_bases_count', 'tools'])
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
            'availableModels' => $this->aiProviderService->getEnabledChatModels($request->user()),
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
            'type' => $request->type,
            'name' => $request->name,
            'description' => $request->description,
            'keywords' => $request->keywords ?? [],
            'status' => AgentStatus::Draft,
            'prompt_template' => $request->prompt_template,
            'model' => $request->model,
            'config' => $request->config ?? [],
            'web_search' => $request->boolean('web_search'),
        ]);

        if ($request->has('knowledge_base_ids')) {
            $agent->syncKnowledgeBases($request->knowledge_base_ids);
        }

        if ($request->has('tool_ids')) {
            $agent->tools()->sync($request->tool_ids);
        }

        return to_route('agents.show', $agent);
    }

    public function show(Request $request, Agent $agent): Response
    {
        $this->authorize('view', $agent);

        $agent->load('tools');
        $agent->loadKnowledgeBases();

        return Inertia::render('standalone-agents/Show', [
            'agent' => $agent,
        ]);
    }

    public function edit(Request $request, Agent $agent): Response
    {
        $this->authorize('update', $agent);

        $activeFlow = $agent->activeFlow();

        $agent->load('tools');
        $agent->loadKnowledgeBases();

        return Inertia::render('standalone-agents/Edit', [
            'agent' => $agent,
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableModels' => $this->aiProviderService->getEnabledChatModels($request->user()),
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
            'web_search' => $request->boolean('web_search'),
        ]);

        if ($request->has('knowledge_base_ids')) {
            $agent->syncKnowledgeBases($request->knowledge_base_ids ?? []);
        }

        if ($request->has('tool_ids')) {
            $agent->tools()->sync($request->tool_ids ?? []);
        }

        return to_route('agents.show', $agent);
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('delete', $agent);

        $agent->delete();

        return to_route('agents.index');
    }

    public function duplicate(Request $request, Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $newAgent = $agent->replicate();
        $newAgent->name = $agent->name.' (Copy)';
        $newAgent->status = AgentStatus::Draft;
        $newAgent->save();

        $newAgent->syncKnowledgeBases($agent->knowledgeBaseIds());
        $newAgent->tools()->sync($agent->tools->pluck('id'));

        return to_route('agents.show', $newAgent);
    }
}
