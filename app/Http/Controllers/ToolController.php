<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Http\Requests\Tool\StoreToolRequest;
use App\Http\Requests\Tool\UpdateToolRequest;
use App\Models\Tool;
use App\Services\ToolConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    public function __construct(
        private readonly ToolConfigService $toolConfigService
    ) {}

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
        $type = ToolType::from($request->type);
        $config = $request->config ?? [];

        // Encrypt sensitive fields
        if ($this->toolConfigService->hasSensitiveFields($type)) {
            $config = $this->toolConfigService->encryptConfig($type, $config);
        }

        $tool = Tool::create([
            'user_id' => $request->user()->id,
            'type' => $request->type,
            'name' => $request->name,
            'description' => $request->description,
            'config' => $config,
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

        // Mask sensitive fields for display
        $toolData = $tool->toArray();
        if ($this->toolConfigService->hasSensitiveFields($tool->type)) {
            $toolData['config'] = $this->toolConfigService->maskSensitiveFields(
                $tool->type,
                $tool->config ?? []
            );
        }

        return Inertia::render('tools/Show', [
            'tool' => $toolData,
        ]);
    }

    public function edit(Request $request, Tool $tool): Response
    {
        if ($tool->user_id !== $request->user()->id) {
            abort(403);
        }

        $tool->load(['groupItems.tool']);

        // Mask sensitive fields for editing
        $toolData = $tool->toArray();
        if ($this->toolConfigService->hasSensitiveFields($tool->type)) {
            $toolData['config'] = $this->toolConfigService->maskSensitiveFields(
                $tool->type,
                $tool->config ?? []
            );
        }

        return Inertia::render('tools/Edit', [
            'tool' => $toolData,
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
        $config = $request->config ?? $tool->config;

        // Handle sensitive fields encryption
        if ($this->toolConfigService->hasSensitiveFields($tool->type)) {
            $config = $this->mergeAndEncryptConfig($tool, $config);
        }

        $tool->update([
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status ?? $tool->status,
            'config' => $config,
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

    /**
     * Merge new config with existing encrypted values and encrypt new sensitive fields.
     *
     * If a sensitive field is empty/null in the new config, keep the existing encrypted value.
     * If a new value is provided, encrypt it.
     */
    private function mergeAndEncryptConfig(Tool $tool, array $newConfig): array
    {
        $existingConfig = $tool->config ?? [];
        $encryptedFields = match ($tool->type->value) {
            'rest_api', 'graphql', 'mcp' => ['auth_config'],
            'database' => ['username', 'password'],
            default => [],
        };

        foreach ($encryptedFields as $field) {
            // If new value is empty but we have an existing value, keep the existing
            if (empty($newConfig[$field]) && ! empty($existingConfig[$field])) {
                $newConfig[$field] = $existingConfig[$field];
            }
        }

        return $this->toolConfigService->encryptConfig($tool->type, $newConfig);
    }
}
