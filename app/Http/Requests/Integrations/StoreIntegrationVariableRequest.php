<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class StoreIntegrationVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'value' => ['nullable', 'string', 'max:10000'],
            'is_secret' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
