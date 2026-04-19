<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteIntegrationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string'],
            'environment_id' => ['nullable', 'string', 'exists:integration_environments,id'],
            // Only used by the ad-hoc execute endpoint:
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'path' => ['nullable', 'string', 'max:1000'],
            'query_params' => ['nullable', 'array'],
            'headers' => ['nullable', 'array'],
            'body_type' => ['nullable', 'string', Rule::in(['none', 'json', 'raw', 'form_urlencoded'])],
            'body_content' => ['nullable', 'string'],
        ];
    }
}
