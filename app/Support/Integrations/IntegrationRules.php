<?php

namespace App\Support\Integrations;

use App\Enums\IntegrationAuthType;
use App\Enums\IntegrationKind;
use App\Enums\Visibility;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for Integration (Connection) validation. Shared by the
 * web Store/Update form requests and the MCP create_integration/update_integration
 * tools so both accept exactly the same shapes.
 *
 * `auth_config` stays loosely typed (`['nullable', 'array']`) on purpose: listing
 * nested paths (e.g. `auth_config.client_id`) makes Laravel's `validated()` drop
 * every other auth_config key — including bearer's `token` and api_key's `value`.
 * The OAuth2-specific requirements live in the `validateOAuth2*` helpers instead,
 * which report errors without narrowing the returned shape.
 */
class IntegrationRules
{
    /**
     * Rules for creating an integration.
     *
     * @return array<string, mixed>
     */
    public static function store(?string $kind): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_url' => self::baseUrlRules($kind, sometimes: false),
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

    /**
     * Rules for a partial update of an integration.
     *
     * @return array<string, mixed>
     */
    public static function update(?string $kind): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_url' => self::baseUrlRules($kind, sometimes: true),
            'is_mcp' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'string', Rule::in(array_column(IntegrationKind::cases(), 'value'))],
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

    /**
     * A database connection's base_url is a DSN, not an http URL, so the http
     * regex only applies to the http/mcp kinds.
     *
     * @return list<string>
     */
    private static function baseUrlRules(?string $kind, bool $sometimes): array
    {
        $rules = $sometimes ? ['sometimes', 'required', 'string', 'max:500'] : ['required', 'string', 'max:500'];
        if ($kind !== IntegrationKind::Database->value) {
            $rules[] = 'regex:/^https?:\/\//i';
        }

        return $rules;
    }

    /**
     * On create, an OAuth2 integration needs its non-secret endpoints and client
     * credentials up front (so the user can't hit "Authorize" with an empty
     * client_id and get a provider 404). PKCE-public auth-code clients are exempt
     * from the secret. Reports errors without narrowing the validated shape.
     *
     * @param  array<string, mixed>  $authConfig
     */
    public static function validateOAuth2OnStore(Validator $validator, array $authConfig, ?string $authType): void
    {
        if (! in_array($authType, ['oauth2_auth_code', 'oauth2_client_credentials'], true)) {
            return;
        }

        $isPublicPkceClient = $authType === 'oauth2_auth_code'
            && filter_var($authConfig['pkce'] ?? false, FILTER_VALIDATE_BOOLEAN);

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
            if (empty($authConfig[$field])) {
                $validator->errors()->add(
                    "auth_config.{$field}",
                    __('validation.required', ['attribute' => $labels[$field] ?? $field]),
                );
            }
        }
    }

    /**
     * On update, blank client_id / client_secret means "keep existing" (per
     * IntegrationService::update merge), so we only shape-check the non-secret
     * endpoints when they're supplied.
     *
     * @param  array<string, mixed>  $authConfig
     */
    public static function validateOAuth2OnUpdate(Validator $validator, array $authConfig): void
    {
        foreach (['authorize_url', 'token_url'] as $field) {
            $value = $authConfig[$field] ?? null;
            if (is_string($value) && $value !== '' && ! preg_match('/^https?:\/\//i', $value)) {
                $validator->errors()->add(
                    "auth_config.{$field}",
                    __('The :attribute must start with http:// or https://.', [
                        'attribute' => str_replace('_', ' ', $field),
                    ]),
                );
            }
        }
    }
}
