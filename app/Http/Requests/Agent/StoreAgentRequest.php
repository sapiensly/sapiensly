<?php

namespace App\Http\Requests\Agent;

use App\Enums\AgentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(AgentType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'prompt_template' => ['nullable', 'string'],
            'model' => ['required', 'string', Rule::in($this->availableModels())],
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

            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['string', 'exists:knowledge_bases,id'],

            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
        ];
    }

    protected function availableModels(): array
    {
        return [
            'claude-sonnet-4-20250514',
            'claude-opus-4-20250514',
            'claude-3-5-haiku-20241022',
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
        ];
    }
}
