<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationAuthType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_url' => ['sometimes', 'required', 'string', 'max:500', 'regex:/^https?:\/\//i'],
            'auth_type' => ['sometimes', 'required', 'string', Rule::in(array_column(IntegrationAuthType::cases(), 'value'))],
            'auth_config' => ['nullable', 'array'],
            'default_headers' => ['nullable', 'array'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:50'],
            'allow_insecure_tls' => ['nullable', 'boolean'],
            'active_environment_id' => ['nullable', 'string', 'exists:integration_environments,id'],
        ];
    }
}
