<?php

namespace App\Enums;

enum IntegrationAuthType: string
{
    case None = 'none';
    case ApiKey = 'api_key';
    case BearerToken = 'bearer';
    case BasicAuth = 'basic';
    case CustomHeaders = 'custom_headers';
    case OAuth2ClientCredentials = 'oauth2_client_credentials';
    case OAuth2AuthorizationCode = 'oauth2_auth_code';

    public function label(): string
    {
        return match ($this) {
            self::None => __('None'),
            self::ApiKey => __('API Key'),
            self::BearerToken => __('Bearer Token'),
            self::BasicAuth => __('Basic Auth'),
            self::CustomHeaders => __('Custom Headers'),
            self::OAuth2ClientCredentials => __('OAuth 2.0 (Client Credentials)'),
            self::OAuth2AuthorizationCode => __('OAuth 2.0 (Authorization Code)'),
        };
    }

    /**
     * Fields expected in the encrypted `auth_config` payload for this type.
     *
     * @return array<int, string>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::None => [],
            self::ApiKey => ['location', 'name', 'value'],
            self::BearerToken => ['token'],
            self::BasicAuth => ['username', 'password'],
            self::CustomHeaders => ['headers'],
            self::OAuth2ClientCredentials => ['token_url', 'client_id', 'client_secret', 'scope', 'audience'],
            self::OAuth2AuthorizationCode => ['authorize_url', 'token_url', 'client_id', 'client_secret', 'redirect_uri', 'scope', 'pkce'],
        };
    }

    public function isOAuth2(): bool
    {
        return in_array($this, [self::OAuth2ClientCredentials, self::OAuth2AuthorizationCode], true);
    }
}
