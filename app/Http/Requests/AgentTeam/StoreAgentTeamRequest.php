<?php

namespace App\Http\Requests\AgentTeam;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],

            'agents' => ['required', 'array', 'size:3'],
            'agents.*.type' => ['required', Rule::enum(AgentType::class)],
            'agents.*.name' => ['required', 'string', 'max:255'],
            'agents.*.description' => ['nullable', 'string', 'max:1000'],
            'agents.*.status' => ['nullable', Rule::enum(AgentStatus::class)],
            'agents.*.prompt_template' => ['nullable', 'string'],
            'agents.*.model' => ['required', 'string', Rule::in($this->availableModels())],
            'agents.*.config' => ['nullable', 'array'],
        ];
    }

    protected function availableModels(): array
    {
        return [
            'claude-sonnet-4-20250514',
            'claude-opus-4-20250514',
            'gpt-4',
            'gpt-4-turbo',
        ];
    }
}
