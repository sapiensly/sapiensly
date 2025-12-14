<?php

namespace App\Http\Requests\Tool;

use App\Enums\AgentStatus;
use App\Models\Tool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tool = $this->route('tool');

        return $tool instanceof Tool && $tool->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::enum(AgentStatus::class)],
            'config' => ['nullable', 'array'],

            'config.name' => ['nullable', 'string', 'max:255'],
            'config.parameters' => ['nullable', 'array'],

            'config.endpoint' => ['nullable', 'string', 'url', 'max:500'],
            'config.auth_type' => ['nullable', 'string', Rule::in(['none', 'bearer', 'api_key', 'basic'])],
            'config.auth_config' => ['nullable', 'array'],

            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['integer', 'exists:tools,id'],
        ];
    }
}
