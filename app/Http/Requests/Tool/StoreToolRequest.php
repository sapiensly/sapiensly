<?php

namespace App\Http\Requests\Tool;

use App\Enums\ToolType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ToolType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
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
