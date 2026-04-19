<?php

namespace App\Services\Integrations\Auth;

use App\Enums\IntegrationAuthType;

class AuthStrategyFactory
{
    /**
     * @var array<string, class-string<AuthStrategy>>
     */
    private const STRATEGIES = [
        IntegrationAuthType::None->value => NoneStrategy::class,
        IntegrationAuthType::ApiKey->value => ApiKeyStrategy::class,
        IntegrationAuthType::BearerToken->value => BearerStrategy::class,
        IntegrationAuthType::BasicAuth->value => BasicAuthStrategy::class,
        IntegrationAuthType::CustomHeaders->value => CustomHeadersStrategy::class,
        IntegrationAuthType::OAuth2ClientCredentials->value => OAuth2ClientCredentialsStrategy::class,
        IntegrationAuthType::OAuth2AuthorizationCode->value => OAuth2AuthorizationCodeStrategy::class,
    ];

    public function make(IntegrationAuthType $type): AuthStrategy
    {
        $class = self::STRATEGIES[$type->value]
            ?? throw new \InvalidArgumentException("Unsupported auth type: {$type->value}");

        return new $class;
    }
}
