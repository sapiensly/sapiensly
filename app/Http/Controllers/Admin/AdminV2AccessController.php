<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Access settings for the admin-v2 screen. Every field is its own key in
 * `app_settings`; the frontend PATCHes one at a time so there's no "save"
 * button and we can render optimistic UI that only has to roll back a
 * single toggle on failure.
 *
 * Empty allowlists mean "open to all" per the product decision:
 *   - domain_allowlist empty → any email domain can register
 *   - ip_allowlist empty + enabled → no restriction (so enabling with no
 *     entries is essentially a no-op; the UI surfaces this)
 */
class AdminV2AccessController extends Controller
{
    private const KEY_REGISTRATION = 'access.registration_open';

    private const KEY_EMAIL_VERIFICATION = 'access.email_verification_required';

    private const KEY_TWO_FACTOR = 'access.two_factor_required';

    private const KEY_IP_ALLOWLIST_ENABLED = 'access.ip_allowlist_enabled';

    private const KEY_IP_ALLOWLIST = 'access.ip_allowlist';

    private const KEY_DOMAIN_ALLOWLIST = 'access.domain_allowlist';

    private const KEY_SESSION_LIFETIME = 'access.session_lifetime_minutes';

    private const KEY_CONCURRENT_SESSIONS = 'access.concurrent_sessions_max';

    private const SESSION_LIFETIME_MIN = 15;

    private const SESSION_LIFETIME_MAX = 10080; // 1 week

    public function index(): Response
    {
        $settings = $this->readSettings();

        return Inertia::render('admin-v2/Access', [
            'settings' => $settings,
            'posture' => $this->derivePosture($settings),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // The frontend sends exactly one field at a time — validate by
        // key presence, not via a single monolithic rules array.
        $validated = $request->validate([
            'registrationOpen' => ['sometimes', 'boolean'],
            'emailVerificationRequired' => ['sometimes', 'boolean'],
            'twoFactorRequired' => ['sometimes', 'boolean'],
            'ipAllowlistEnabled' => ['sometimes', 'boolean'],
            'ipAllowlist' => ['sometimes', 'array'],
            'ipAllowlist.*' => ['string', 'max:45'],
            'domainAllowlist' => ['sometimes', 'array'],
            'domainAllowlist.*' => ['string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'sessionLifetimeMinutes' => [
                'sometimes',
                'integer',
                'between:'.self::SESSION_LIFETIME_MIN.','.self::SESSION_LIFETIME_MAX,
            ],
            'concurrentSessionsMax' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($request->has('registrationOpen')) {
            AppSetting::setBool(self::KEY_REGISTRATION, (bool) $validated['registrationOpen']);
            // Keep the legacy key in sync so the old admin + public register
            // form continue to honour the toggle.
            AppSetting::setValue(
                'registration_enabled',
                $validated['registrationOpen'] ? 'true' : 'false',
            );
        }
        if ($request->has('emailVerificationRequired')) {
            AppSetting::setBool(self::KEY_EMAIL_VERIFICATION, (bool) $validated['emailVerificationRequired']);
        }
        if ($request->has('twoFactorRequired')) {
            AppSetting::setBool(self::KEY_TWO_FACTOR, (bool) $validated['twoFactorRequired']);
        }
        if ($request->has('ipAllowlistEnabled')) {
            AppSetting::setBool(self::KEY_IP_ALLOWLIST_ENABLED, (bool) $validated['ipAllowlistEnabled']);
        }
        if ($request->has('ipAllowlist')) {
            AppSetting::setStringList(self::KEY_IP_ALLOWLIST, $validated['ipAllowlist']);
        }
        if ($request->has('domainAllowlist')) {
            // Normalise to lowercase; AppSetting dedupes case-insensitively.
            $lowered = array_map('strtolower', $validated['domainAllowlist']);
            AppSetting::setStringList(self::KEY_DOMAIN_ALLOWLIST, $lowered);
        }
        if ($request->has('sessionLifetimeMinutes')) {
            AppSetting::setValue(
                self::KEY_SESSION_LIFETIME,
                (string) $validated['sessionLifetimeMinutes'],
            );
        }
        if ($request->has('concurrentSessionsMax')) {
            AppSetting::setValue(
                self::KEY_CONCURRENT_SESSIONS,
                $validated['concurrentSessionsMax'] === null
                    ? 'null'
                    : (string) $validated['concurrentSessionsMax'],
            );
        }

        return back()->with('success', __('Access setting updated.'));
    }

    /**
     * List users that still don't have 2FA confirmed. Surfaced on the UI
     * warning modal when the admin flips `twoFactorRequired` on.
     */
    public function usersWithoutTwoFactor(): JsonResponse
    {
        $users = User::query()
            ->whereNull('two_factor_confirmed_at')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email']);

        return response()->json([
            'count' => User::whereNull('two_factor_confirmed_at')->count(),
            'users' => $users,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(): array
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

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array{id: string, label: string, ok: bool, hint?: string, fixRoute?: string}>
     */
    private function derivePosture(array $settings): array
    {
        $posture = [];

        $posture[] = [
            'id' => 'two_factor',
            'label' => __('Two-factor required for all users'),
            'ok' => $settings['twoFactorRequired'] === true,
            'hint' => $settings['twoFactorRequired']
                ? null
                : __('Turn on to force sign-in via authenticator.'),
        ];

        $posture[] = [
            'id' => 'email_verification',
            'label' => __('Email verification before sign-in'),
            'ok' => $settings['emailVerificationRequired'] === true,
            'hint' => $settings['emailVerificationRequired']
                ? null
                : __('Require email click-through to block fake accounts.'),
        ];

        $posture[] = [
            'id' => 'ip_allowlist',
            'label' => __('IP allowlist configured'),
            'ok' => $settings['ipAllowlistEnabled'] && count($settings['ipAllowlist']) > 0,
            'hint' => $settings['ipAllowlistEnabled']
                ? (count($settings['ipAllowlist']) === 0
                    ? __('Allowlist is enabled but empty — no entries means no enforcement.')
                    : null)
                : __('Optional hardening: restrict admin access to known ranges.'),
        ];

        return array_values(array_map(
            fn ($item) => array_filter($item, fn ($v) => $v !== null),
            $posture,
        ));
    }
}
