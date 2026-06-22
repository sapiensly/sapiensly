<?php

namespace App\Mcp\Tools\Agents\Concerns;

use App\Models\Agent;
use App\Models\Tool;
use App\Models\User;
use App\Services\AiProviderService;
use Illuminate\Validation\Rule;

/**
 * Shared presentation + validation helpers for the agent management tools, so
 * create/update/get expose an identical agent shape and validate the model
 * against the same enabled-chat catalog the web forms use.
 */
trait PresentsAgent
{
    /**
     * The JSON shape returned for a single agent (mirrors GetAgentTool).
     *
     * @return array<string, mixed>
     */
    protected function agentPayload(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'type' => $agent->type?->value,
            'status' => $agent->status?->value,
            'description' => $agent->description,
            'keywords' => $agent->keywords ?? [],
            'model' => $agent->model,
            'web_search' => $agent->web_search,
            'system_prompt' => $agent->prompt_template,
            'config' => $agent->config ?? [],
            'tools' => $agent->tools()->get()->map(fn (Tool $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type?->value,
                'effect' => $t->effect?->value,
            ])->values(),
            'knowledge_bases' => $agent->loadKnowledgeBases(['id', 'name'])
                ->map(fn ($kb) => ['id' => $kb->id, 'name' => $kb->name])
                ->values(),
        ];
    }

    /**
     * Validation rule for the model field: restrict it to the caller's enabled
     * chat catalog (tenant key or platform-wide global key), falling back to a
     * plain string when the catalog is empty. Mirrors the Store/Update form
     * requests so MCP and the UI accept exactly the same model ids.
     *
     * @return array<int, mixed>
     */
    protected function chatModelRule(?User $user, bool $required): array
    {
        $reachable = collect(app(AiProviderService::class)->getEnabledChatModels($user))
            ->pluck('value')
            ->all();

        $presence = $required ? 'required' : 'sometimes';

        return $reachable === []
            ? [$presence, 'string', 'max:100']
            : [$presence, 'string', Rule::in($reachable)];
    }

    /**
     * The nested config rules shared by create/update (temperature, RAG params,
     * tool execution), matching the web form requests.
     *
     * @return array<string, mixed>
     */
    protected function configRules(): array
    {
        return [
            'config' => ['nullable', 'array'],
            'config.temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'config.guardrails' => ['nullable', 'array'],
            'config.rag_params' => ['nullable', 'array'],
            'config.rag_params.chunk_size' => ['nullable', 'integer', 'min:100', 'max:4000'],
            'config.rag_params.top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
            'config.rag_params.similarity_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'config.tool_execution' => ['nullable', 'array'],
            'config.tool_execution.timeout' => ['nullable', 'integer', 'min:1000', 'max:300000'],
            'config.tool_execution.retry_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'config.web_search' => ['nullable', 'array'],
            'config.web_search.max_results' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
