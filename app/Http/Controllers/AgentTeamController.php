<?php

namespace App\Http\Controllers;

use App\Enums\AgentType;
use App\Http\Requests\AgentTeam\StoreAgentTeamRequest;
use App\Http\Requests\AgentTeam\UpdateAgentTeamRequest;
use App\Models\Agent;
use App\Models\AgentTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentTeamController extends Controller
{
    public function index(Request $request): Response
    {
        $teams = AgentTeam::query()
            ->where('user_id', $request->user()->id)
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

        // Get standalone agents visible to user, grouped by type
        $standaloneAgents = Agent::visibleTo($user)
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
            'availableModels' => $this->getAvailableModels(),
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
                abort(403, 'You do not have access to one or more selected agents.');
            }
        }

        $team = AgentTeam::create([
            'user_id' => $user->id,
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
        if ($agentTeam->user_id !== $request->user()->id) {
            abort(403);
        }

        return Inertia::render('agents/Show', [
            'team' => $agentTeam->load('agents'),
        ]);
    }

    public function edit(Request $request, AgentTeam $agentTeam): Response
    {
        if ($agentTeam->user_id !== $request->user()->id) {
            abort(403);
        }

        return Inertia::render('agents/Edit', [
            'team' => $agentTeam->load('agents'),
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableModels' => $this->getAvailableModels(),
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
        if ($agentTeam->user_id !== $request->user()->id) {
            abort(403);
        }

        $agentTeam->delete();

        return to_route('agent-teams.index');
    }

    private function getAvailableModels(): array
    {
        return [
            ['value' => 'claude-sonnet-4-20250514', 'label' => 'Claude Sonnet 4'],
            ['value' => 'claude-opus-4-20250514', 'label' => 'Claude Opus 4'],
            ['value' => 'gpt-4', 'label' => 'GPT-4'],
            ['value' => 'gpt-4-turbo', 'label' => 'GPT-4 Turbo'],
        ];
    }
}
