<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntegrationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(
            $this->sharedRules(),
            [
                'name' => ['required', 'string', 'max:150'],
                'method' => ['required', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
                'path' => ['required', 'string', 'max:1000'],
            ],
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function sharedRules(): array
    {
        $maxBodyBytes = (int) config('integrations.max_request_body_bytes', 1_048_576);

        return [
            'description' => ['nullable', 'string', 'max:2000'],
            'folder' => ['nullable', 'string', 'max:100'],
            'query_params' => ['nullable', 'array'],
            'query_params.*.key' => ['required_with:query_params', 'string', 'max:100'],
            'query_params.*.value' => ['nullable', 'string', 'max:1000'],
            'query_params.*.enabled' => ['nullable', 'boolean'],
            'headers' => ['nullable', 'array'],
            'headers.*.key' => ['required_with:headers', 'string', 'max:100'],
            'headers.*.value' => ['nullable', 'string', 'max:1000'],
            'headers.*.enabled' => ['nullable', 'boolean'],
            'body_type' => ['nullable', 'string', Rule::in(['none', 'json', 'raw', 'form_urlencoded'])],
            'body_content' => ['nullable', 'string', "max:{$maxBodyBytes}"],
            'timeout_ms' => ['nullable', 'integer', 'min:500', 'max:'.config('integrations.max_timeout_ms', 30_000)],
            'follow_redirects' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
