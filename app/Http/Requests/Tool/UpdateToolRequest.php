<?php

namespace App\Http\Requests\Tool;

use App\Enums\AgentStatus;
use App\Models\Tool;
use App\Support\Tools\ToolConfigRules;
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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::enum(AgentStatus::class)],
            'config' => ['nullable', 'array'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
        ];

        $tool = $this->route('tool');
        $type = $tool?->type?->value ?? $this->input('type');

        return array_merge($rules, ToolConfigRules::forType($type, $this->user()));
    }
}
