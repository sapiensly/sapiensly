<?php

namespace App\Http\Controllers;

use App\Enums\AgentType;
use App\Http\Requests\AgentTeam\StoreAgentTeamRequest;
use App\Http\Requests\AgentTeam\UpdateAgentTeamRequest;
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

    public function create(): Response
    {
        return Inertia::render('agents/Create', [
            'agentTypes' => collect(AgentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableModels' => $this->getAvailableModels(),
        ]);
    }

    public function store(StoreAgentTeamRequest $request): RedirectResponse
    {
        $team = AgentTeam::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'description' => $request->description,
            'status' => 'draft',
        ]);

        foreach ($request->agents as $agentData) {
            $team->agents()->create($agentData);
        }

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
