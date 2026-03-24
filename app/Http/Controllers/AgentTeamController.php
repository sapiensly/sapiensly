<?php

namespace App\Http\Controllers;

use App\Enums\AgentType;
use App\Enums\MessageRole;
use App\Enums\Visibility;
use App\Http\Requests\AgentTeam\StoreAgentTeamRequest;
use App\Http\Requests\AgentTeam\UpdateAgentTeamRequest;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Conversation;
use App\Services\AiProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentTeamController extends Controller
{
    public function __construct(
        private AiProviderService $aiProviderService,
    ) {}

    public function index(Request $request): Response
    {
        $teams = AgentTeam::query()
            ->forAccountContext($request->user())
            ->withCount('agents')
            ->latest()
            ->paginate(12);

        return Inertia::render('agents/Index', [
            'teams' => $teams,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        // Get standalone agents in user's current account context, grouped by type
        $standaloneAgents = Agent::forAccountContext($user)
            ->standalone()
            ->get()
            ->groupBy('type')
            ->map(fn ($agents) => $agents->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description,
                'model' => $agent->model,
                'status' => $agent->status,
            ])->values());

        return Inertia::render('agents/Create', [
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableModels' => $this->aiProviderService->getAvailableModels($request->user()),
            'standaloneAgents' => [
                'triage' => $standaloneAgents->get('triage', collect())->all(),
                'knowledge' => $standaloneAgents->get('knowledge', collect())->all(),
                'action' => $standaloneAgents->get('action', collect())->all(),
            ],
        ]);
    }

    public function store(StoreAgentTeamRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Verify user has access to selected agents
        $agentIds = array_values($request->agent_ids);
        $agents = Agent::whereIn('id', $agentIds)->get();

        foreach ($agents as $agent) {
            if (! $agent->isVisibleTo($user)) {
                abort(403, __('You do not have access to one or more selected agents.'));
            }
        }

        $team = AgentTeam::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'name' => $request->name,
            'description' => $request->description,
            'keywords' => $request->keywords ?? [],
            'status' => 'draft',
        ]);

        // Attach agents to team
        Agent::whereIn('id', $agentIds)->update(['agent_team_id' => $team->id]);

        return to_route('agent-teams.show', $team);
    }

    public function show(Request $request, AgentTeam $agentTeam): Response
    {
        if (! $agentTeam->isVisibleTo($request->user())) {
            abort(403);
        }

        return Inertia::render('agents/Show', [
            'team' => $agentTeam->load('agents'),
        ]);
    }

    public function edit(Request $request, AgentTeam $agentTeam): Response
    {
        if (! $agentTeam->isOwnedBy($request->user())) {
            abort(403);
        }

        return Inertia::render('agents/Edit', [
            'team' => $agentTeam->load('agents'),
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableModels' => $this->aiProviderService->getAvailableModels($request->user()),
        ]);
    }

    public function update(UpdateAgentTeamRequest $request, AgentTeam $agentTeam): RedirectResponse
    {
        $agentTeam->update([
            'name' => $request->name,
            'description' => $request->description,
            'keywords' => $request->keywords ?? [],
            'status' => $request->status ?? $agentTeam->status,
        ]);

        foreach ($request->agents as $agentData) {
            $agentTeam->agents()
                ->where('type', $agentData['type'])
                ->update($agentData);
        }

        return to_route('agent-teams.show', $agentTeam);
    }

    public function destroy(Request $request, AgentTeam $agentTeam): RedirectResponse
    {
        if (! $agentTeam->isOwnedBy($request->user())) {
            abort(403);
        }

        $agentTeam->delete();

        return to_route('agent-teams.index');
    }

    public function chat(Request $request, AgentTeam $agentTeam): Response
    {
        if (! $agentTeam->isVisibleTo($request->user())) {
            abort(403);
        }

        // Get or create a conversation for this team
        $conversation = Conversation::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'team_id' => $agentTeam->id,
            ],
            [
                'title' => "Chat with {$agentTeam->name}",
            ]
        );

        return Inertia::render('agent-teams/Chat', [
            'team' => $agentTeam->load(['triageAgent', 'knowledgeAgent', 'actionAgent']),
            'conversation' => $conversation->load('messages'),
        ]);
    }

    public function sendMessage(Request $request, AgentTeam $agentTeam): RedirectResponse
    {
        if (! $agentTeam->isVisibleTo($request->user())) {
            abort(403);
        }

        $request->validate([
            'message' => 'required|string|max:10000',
        ]);

        // Get or create conversation
        $conversation = Conversation::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'team_id' => $agentTeam->id,
            ],
            [
                'title' => "Chat with {$agentTeam->name}",
            ]
        );

        // Save user message
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $request->message,
        ]);

        return back();
    }

    public function newConversation(Request $request, AgentTeam $agentTeam): RedirectResponse
    {
        if (! $agentTeam->isVisibleTo($request->user())) {
            abort(403);
        }

        // Delete existing conversation for this team
        Conversation::where('user_id', $request->user()->id)
            ->where('team_id', $agentTeam->id)
            ->delete();

        return to_route('agent-teams.chat', $agentTeam);
    }
}
