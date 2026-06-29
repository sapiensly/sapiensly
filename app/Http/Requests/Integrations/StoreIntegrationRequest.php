<?php

namespace App\Http\Requests\Integrations;

use App\Enums\IntegrationAuthType;
use App\Enums\IntegrationKind;
use App\Enums\Visibility;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // auth_config stays loosely typed ('array') on purpose: adding
        // explicit nested paths here (e.g. `auth_config.client_id`) makes
        // `validated()` drop every other auth_config key — including
        // bearer's `token` and api_key's `value`. The OAuth-specific
        // requirements are enforced in `withValidator()` instead, which
        // reports errors without narrowing the returned shape.
        // A database connection's base_url is a DSN, not an http URL, so the
        // http regex only applies to the http/mcp kinds.
        $baseUrlRules = ['required', 'string', 'max:500'];
        if ($this->input('kind') !== IntegrationKind::Database->value) {
            $baseUrlRules[] = 'regex:/^https?:\/\//i';
        }

        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_url' => $baseUrlRules,
            'is_mcp' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'string', Rule::in(array_column(IntegrationKind::cases(), 'value'))],
            'auth_type' => ['required', 'string', Rule::in(array_column(IntegrationAuthType::cases(), 'value'))],
            'auth_config' => ['nullable', 'array'],
            'default_headers' => ['nullable', 'array'],
            'visibility' => ['nullable', 'string', Rule::in(array_column(Visibility::cases(), 'value'))],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:50'],
            'allow_insecure_tls' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Without these, saving an OAuth2 integration would let the user
        // hit "Authorize" with client_id= in the redirect URL and get a
        // provider 404. Require the non-secret endpoints and both client
        // credentials up front on create.
        $validator->after(function (Validator $v) {
            $authType = $this->input('auth_type');
            if (! in_array($authType, ['oauth2_auth_code', 'oauth2_client_credentials'], true)) {
                return;
            }

            $cfg = (array) $this->input('auth_config', []);

            // PKCE authorization-code clients are typically public — the
            // server issues a client_id with no secret (the MCP default via
            // dynamic registration). Only require a secret for confidential
            // (non-PKCE) auth-code clients and for client-credentials.
            $isPublicPkceClient = $authType === 'oauth2_auth_code'
                && filter_var($cfg['pkce'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $required = ['token_url', 'client_id'];
            if (! $isPublicPkceClient) {
                $required[] = 'client_secret';
            }
            if ($authType === 'oauth2_auth_code') {
                $required[] = 'authorize_url';
            }

            $labels = [
                'authorize_url' => __('Authorize URL'),
                'token_url' => __('Token URL'),
                'client_id' => __('Client ID'),
                'client_secret' => __('Client Secret'),
            ];

            foreach ($required as $field) {
                if (empty($cfg[$field])) {
                    $v->errors()->add(
                        "auth_config.{$field}",
                        __('validation.required', ['attribute' => $labels[$field]]),
                    );
                }
            }
        });
    }
}
