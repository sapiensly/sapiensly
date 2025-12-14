<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Http\Requests\Tool\StoreToolRequest;
use App\Http\Requests\Tool\UpdateToolRequest;
use App\Models\Tool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    public function index(Request $request): Response
    {
        $typeFilter = $request->query('type');

        $query = Tool::query()
            ->where('user_id', $request->user()->id)
            ->latest();

        if ($typeFilter && in_array($typeFilter, array_column(ToolType::cases(), 'value'))) {
            $query->where('type', $typeFilter);
        }

        $tools = $query->paginate(12)->withQueryString();

        $toolsByType = Tool::query()
            ->where('user_id', $request->user()->id)
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return Inertia::render('tools/Index', [
            'tools' => $tools,
            'toolsByType' => $toolsByType,
            'currentType' => $typeFilter,
            'toolTypes' => collect(ToolType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $request->query('type');

        return Inertia::render('tools/Create', [
            'selectedType' => $type,
            'toolTypes' => collect(ToolType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableTools' => $request->user()->tools()
                ->whereIn('type', ['function', 'mcp'])
                ->where('status', 'active')
                ->get(['id', 'name', 'type']),
        ]);
    }

    public function store(StoreToolRequest $request): RedirectResponse
    {
        $tool = Tool::create([
            'user_id' => $request->user()->id,
            'type' => $request->type,
            'name' => $request->name,
            'description' => $request->description,
            'config' => $request->config ?? [],
            'status' => AgentStatus::Draft,
        ]);

        if ($request->type === 'group' && $request->has('tool_ids')) {
            foreach ($request->tool_ids as $index => $toolId) {
                $tool->groupItems()->create([
                    'tool_id' => $toolId,
                    'order' => $index,
                ]);
            }
        }

        return to_route('tools.show', $tool);
    }

    public function show(Request $request, Tool $tool): Response
    {
        if ($tool->user_id !== $request->user()->id) {
            abort(403);
        }

        $tool->load(['groupItems.tool']);

        return Inertia::render('tools/Show', [
            'tool' => $tool,
        ]);
    }

    public function edit(Request $request, Tool $tool): Response
    {
        if ($tool->user_id !== $request->user()->id) {
            abort(403);
        }

        $tool->load(['groupItems.tool']);

        return Inertia::render('tools/Edit', [
            'tool' => $tool,
            'toolTypes' => collect(ToolType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ]),
            'availableTools' => $request->user()->tools()
                ->whereIn('type', ['function', 'mcp'])
                ->where('status', 'active')
                ->where('id', '!=', $tool->id)
                ->get(['id', 'name', 'type']),
        ]);
    }

    public function update(UpdateToolRequest $request, Tool $tool): RedirectResponse
    {
        $tool->update([
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status ?? $tool->status,
            'config' => $request->config ?? $tool->config,
        ]);

        if ($tool->type->value === 'group' && $request->has('tool_ids')) {
            $tool->groupItems()->delete();
            foreach ($request->tool_ids as $index => $toolId) {
                $tool->groupItems()->create([
                    'tool_id' => $toolId,
                    'order' => $index,
                ]);
            }
        }

        return to_route('tools.show', $tool);
    }

    public function destroy(Request $request, Tool $tool): RedirectResponse
    {
        if ($tool->user_id !== $request->user()->id) {
            abort(403);
        }

        $tool->delete();

        return to_route('tools.index');
    }
}
