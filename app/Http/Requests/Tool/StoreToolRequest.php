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
        $rules = [
            'type' => ['required', Rule::enum(ToolType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'config' => ['nullable', 'array'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => ['string', 'exists:tools,id'],
        ];

        return array_merge($rules, $this->getTypeSpecificRules());
    }

    private function getTypeSpecificRules(): array
    {
        return match ($this->input('type')) {
            'function' => $this->functionRules(),
            'mcp' => $this->mcpRules(),
            'rest_api' => $this->restApiRules(),
            'graphql' => $this->graphqlRules(),
            'database' => $this->databaseRules(),
            default => [],
        };
    }

    private function functionRules(): array
    {
        return [
            'config.name' => ['nullable', 'string', 'max:255'],
            'config.description' => ['nullable', 'string', 'max:1000'],
            'config.parameters' => ['nullable', 'array'],
        ];
    }

    private function mcpRules(): array
    {
        return [
            'config.endpoint' => ['required', 'string', 'url', 'max:500'],
            'config.auth_type' => ['required', 'string', Rule::in(['none', 'bearer', 'api_key', 'basic'])],
            'config.auth_config' => ['nullable', 'array'],
        ];
    }

    private function restApiRules(): array
    {
        return [
            'config.base_url' => ['required', 'string', 'url', 'max:500'],
            'config.method' => ['required', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'config.path' => ['nullable', 'string', 'max:500'],
            'config.headers' => ['nullable', 'array'],
            'config.auth_type' => ['required', 'string', Rule::in(['none', 'bearer', 'api_key', 'basic', 'oauth2'])],
            'config.auth_config' => ['nullable', 'array'],
            'config.request_body_template' => ['nullable', 'string'],
            'config.response_mapping' => ['nullable', 'array'],
        ];
    }

    private function graphqlRules(): array
    {
        return [
            'config.endpoint' => ['required', 'string', 'url', 'max:500'],
            'config.operation_type' => ['required', 'string', Rule::in(['query', 'mutation'])],
            'config.operation' => ['required', 'string', 'max:10000'],
            'config.variables_template' => ['nullable', 'array'],
            'config.auth_type' => ['nullable', 'string', Rule::in(['none', 'bearer', 'api_key'])],
            'config.auth_config' => ['nullable', 'array'],
            'config.response_mapping' => ['nullable', 'array'],
        ];
    }

    private function databaseRules(): array
    {
        return [
            'config.driver' => ['required', 'string', Rule::in(['pgsql', 'mysql', 'sqlite', 'sqlsrv'])],
            'config.host' => ['required_unless:config.driver,sqlite', 'nullable', 'string', 'max:255'],
            'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'config.database' => ['required', 'string', 'max:255'],
            'config.username' => ['required_unless:config.driver,sqlite', 'nullable', 'string', 'max:255'],
            'config.password' => ['nullable', 'string'],
            'config.query_template' => ['required', 'string', 'max:10000'],
            'config.read_only' => ['nullable', 'boolean'],
        ];
    }
}
