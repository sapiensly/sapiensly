<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\AccessPolicy;
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
 * The keys, defaults and posture rules live in {@see AccessPolicy}, shared with
 * the `get_access_policy` / `set_access_policy` MCP tools.
 */
class AdminAccessController extends Controller
{
    public function __construct(
        private readonly AccessPolicy $policy,
    ) {}

    public function index(): Response
    {
        $settings = $this->policy->read();

        return Inertia::render('admin/Access', [
            'settings' => $settings,
            'posture' => $this->policy->posture($settings),
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
                'between:'.AccessPolicy::SESSION_LIFETIME_MIN.','.AccessPolicy::SESSION_LIFETIME_MAX,
            ],
            'concurrentSessionsMax' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($request->has('registrationOpen')) {
            $this->policy->setRegistrationOpen((bool) $validated['registrationOpen']);
        }
        if ($request->has('emailVerificationRequired')) {
            $this->policy->setEmailVerificationRequired((bool) $validated['emailVerificationRequired']);
        }
        if ($request->has('twoFactorRequired')) {
            $this->policy->setTwoFactorRequired((bool) $validated['twoFactorRequired']);
        }
        if ($request->has('ipAllowlistEnabled')) {
            $this->policy->setIpAllowlistEnabled((bool) $validated['ipAllowlistEnabled']);
        }
        if ($request->has('ipAllowlist')) {
            $this->policy->setIpAllowlist($validated['ipAllowlist']);
        }
        if ($request->has('domainAllowlist')) {
            $this->policy->setDomainAllowlist($validated['domainAllowlist']);
        }
        if ($request->has('sessionLifetimeMinutes')) {
            $this->policy->setSessionLifetimeMinutes((int) $validated['sessionLifetimeMinutes']);
        }
        if ($request->has('concurrentSessionsMax')) {
            $this->policy->setConcurrentSessionsMax(
                $validated['concurrentSessionsMax'] === null ? null : (int) $validated['concurrentSessionsMax'],
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
}
