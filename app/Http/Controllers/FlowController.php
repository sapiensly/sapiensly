<?php

namespace App\Http\Controllers;

use App\Enums\FlowStatus;
use App\Http\Requests\Flow\StoreFlowRequest;
use App\Http\Requests\Flow\UpdateFlowRequest;
use App\Models\Agent;
use App\Models\Flow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FlowController extends Controller
{
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

    public function create(Agent $agent): Response
    {
        return Inertia::render('flows/Edit', [
            'agent' => $agent,
            'flow' => null,
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

    public function edit(Agent $agent, Flow $flow): Response
    {
        return Inertia::render('flows/Edit', [
            'agent' => $agent,
            'flow' => $flow,
        ]);
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
