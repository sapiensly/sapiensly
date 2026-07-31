<?php

namespace App\Services\Platform;

use App\Models\AppSetting;

/**
 * The platform's sign-in policy: who may register, what they must prove, and
 * from where. Each field is its own `app_settings` key under `access.*`.
 *
 * Extracted from the admin Access screen so the web UI and the
 * `get_access_policy` / `set_access_policy` MCP tools read and write the SAME
 * key names. Two copies of a key list means the day someone renames one, the
 * other silently starts writing a setting nothing reads.
 *
 * Empty allowlists mean "open to all", per the product decision:
 *   - domain_allowlist empty → any email domain may register
 *   - ip_allowlist empty while enabled → no restriction in practice, which the
 *     posture check calls out rather than pretending is protection.
 */
class AccessPolicy
{
    public const KEY_REGISTRATION = 'access.registration_open';

    public const KEY_EMAIL_VERIFICATION = 'access.email_verification_required';

    public const KEY_TWO_FACTOR = 'access.two_factor_required';

    public const KEY_IP_ALLOWLIST_ENABLED = 'access.ip_allowlist_enabled';

    public const KEY_IP_ALLOWLIST = 'access.ip_allowlist';

    public const KEY_DOMAIN_ALLOWLIST = 'access.domain_allowlist';

    public const KEY_SESSION_LIFETIME = 'access.session_lifetime_minutes';

    public const KEY_CONCURRENT_SESSIONS = 'access.concurrent_sessions_max';

    public const SESSION_LIFETIME_MIN = 15;

    public const SESSION_LIFETIME_MAX = 10080; // one week

    /**
     * @return array{
     *     registrationOpen: bool,
     *     emailVerificationRequired: bool,
     *     twoFactorRequired: bool,
     *     ipAllowlistEnabled: bool,
     *     ipAllowlist: list<string>,
     *     domainAllowlist: list<string>,
     *     sessionLifetimeMinutes: int,
     *     concurrentSessionsMax: ?int,
     * }
     */
    public function read(): array
    {
        return [
            'registrationOpen' => AppSetting::getBool(self::KEY_REGISTRATION, true),
            'emailVerificationRequired' => AppSetting::getBool(self::KEY_EMAIL_VERIFICATION, true),
            'twoFactorRequired' => AppSetting::getBool(self::KEY_TWO_FACTOR, false),
            'ipAllowlistEnabled' => AppSetting::getBool(self::KEY_IP_ALLOWLIST_ENABLED, false),
            'ipAllowlist' => AppSetting::getStringList(self::KEY_IP_ALLOWLIST),
            'domainAllowlist' => AppSetting::getStringList(self::KEY_DOMAIN_ALLOWLIST),
            'sessionLifetimeMinutes' => AppSetting::getInt(self::KEY_SESSION_LIFETIME, 120),
            'concurrentSessionsMax' => AppSetting::getNullableInt(self::KEY_CONCURRENT_SESSIONS),
        ];
    }

    public function setRegistrationOpen(bool $value): void
    {
        AppSetting::setBool(self::KEY_REGISTRATION, $value);
        // The public register form and the legacy admin still read the old key.
        AppSetting::setValue('registration_enabled', $value ? 'true' : 'false');
    }

    public function setEmailVerificationRequired(bool $value): void
    {
        AppSetting::setBool(self::KEY_EMAIL_VERIFICATION, $value);
    }

    public function setTwoFactorRequired(bool $value): void
    {
        AppSetting::setBool(self::KEY_TWO_FACTOR, $value);
    }

    public function setIpAllowlistEnabled(bool $value): void
    {
        AppSetting::setBool(self::KEY_IP_ALLOWLIST_ENABLED, $value);
    }

    /**
     * @param  list<string>  $values
     */
    public function setIpAllowlist(array $values): void
    {
        AppSetting::setStringList(self::KEY_IP_ALLOWLIST, $values);
    }

    /**
     * @param  list<string>  $values
     */
    public function setDomainAllowlist(array $values): void
    {
        AppSetting::setStringList(self::KEY_DOMAIN_ALLOWLIST, array_map('strtolower', $values));
    }

    public function setSessionLifetimeMinutes(int $minutes): void
    {
        AppSetting::setValue(self::KEY_SESSION_LIFETIME, (string) $minutes);
    }

    public function setConcurrentSessionsMax(?int $max): void
    {
        AppSetting::setValue(self::KEY_CONCURRENT_SESSIONS, $max === null ? 'null' : (string) $max);
    }

    /**
     * The hardening checklist derived from the current settings.
     *
     * @param  array<string, mixed>|null  $settings
     * @return list<array{id: string, label: string, ok: bool, hint?: string}>
     */
    public function posture(?array $settings = null): array
    {
        $settings ??= $this->read();

        $posture = [
            [
                'id' => 'two_factor',
                'label' => __('Two-factor required for all users'),
                'ok' => $settings['twoFactorRequired'] === true,
                'hint' => $settings['twoFactorRequired']
                    ? null
                    : __('Turn on to force sign-in via authenticator.'),
            ],
            [
                'id' => 'email_verification',
                'label' => __('Email verification before sign-in'),
                'ok' => $settings['emailVerificationRequired'] === true,
                'hint' => $settings['emailVerificationRequired']
                    ? null
                    : __('Require email click-through to block fake accounts.'),
            ],
            [
                'id' => 'ip_allowlist',
                'label' => __('IP allowlist configured'),
                'ok' => $settings['ipAllowlistEnabled'] && count($settings['ipAllowlist']) > 0,
                'hint' => $settings['ipAllowlistEnabled']
                    ? (count($settings['ipAllowlist']) === 0
                        ? __('Allowlist is enabled but empty — no entries means no enforcement.')
                        : null)
                    : __('Optional hardening: restrict admin access to known ranges.'),
            ],
        ];

        return array_values(array_map(
            static fn (array $item) => array_filter($item, static fn ($value) => $value !== null),
            $posture,
        ));
    }
}
