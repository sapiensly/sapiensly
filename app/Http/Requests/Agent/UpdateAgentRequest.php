<?php

namespace App\Http\Requests\Agent;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Services\AiProviderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agent = $this->route('agent');

        return $agent instanceof Agent && $agent->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'status' => ['nullable', Rule::enum(AgentStatus::class)],
            'prompt_template' => ['nullable', 'string'],
            'model' => $this->modelRule(),
            'web_search' => ['nullable', 'boolean'],
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

            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['string', 'exists:tenant.knowledge_bases,id'],

            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
        ];
    }

    /**
     * Validate the model against the enabled chat catalog (same source as the
     * edit form's picker — models usable via the tenant's own key or the
     * platform-wide global key). Falls back to a plain string when the catalog
     * is empty.
     *
     * @return array<int, mixed>
     */
    protected function modelRule(): array
    {
        $reachable = collect(app(AiProviderService::class)->getEnabledChatModels($this->user()))
            ->pluck('value')
            ->all();

        return $reachable === []
            ? ['required', 'string', 'max:100']
            : ['required', 'string', Rule::in($reachable)];
    }
}
