<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSsoConnectionRequest;
use App\Models\Organization;
use App\Models\OrganizationSsoConnection;
use App\Services\Sso\OidcLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only configuration of the organization's enterprise OIDC connection.
 * The login leg lives in Auth\OrganizationSsoController; this is the settings
 * surface. Every action is gated by OrganizationPolicy@manageSso.
 */
class SsoConnectionController extends Controller
{
    public function __construct(private OidcLoginService $oidc) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->organization_id) {
            return to_route('organization.create');
        }

        $organization = $user->organization;
        $this->authorize('manageSso', $organization);

        $connection = $organization->ssoConnection;

        return Inertia::render('settings/Sso', [
            'connection' => $this->present($organization, $connection),
            'ssoUrl' => route('sso.redirect', ['slug' => $organization->slug]),
        ]);
    }

    /**
     * Probe an issuer's discovery document so the owner can confirm the IdP is
     * reachable before saving. Returns the resolved endpoints; persists nothing.
     */
    public function discover(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $this->authorize('manageSso', $organization);

        $validated = $request->validate([
            'issuer' => ['required', 'url', 'max:500'],
        ]);

        try {
            $endpoints = $this->oidc->discover($validated['issuer']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['issuer' => $e->getMessage()]);
        }

        return back()->with('discovered', $endpoints);
    }

    public function update(UpdateSsoConnectionRequest $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $connection = $organization->ssoConnection;
        $enabled = $request->boolean('enabled');

        $config = $connection?->config ?? [];

        // When enabling, endpoints are always (re)resolved from the issuer's
        // discovery document — never taken from the client — so a tampered or
        // stale endpoint can't be persisted.
        if ($enabled) {
            try {
                $endpoints = $this->oidc->discover((string) $request->input('issuer'));
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['issuer' => $e->getMessage()]);
            }
            $config = array_merge($config, $endpoints);
        }

        // A blank secret on update keeps the stored one.
        $secret = (string) $request->input('client_secret', '');
        if ($secret !== '') {
            $config['client_secret'] = $secret;
        }

        OrganizationSsoConnection::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'enabled' => $enabled,
                'auto_provision' => $request->boolean('auto_provision'),
                'issuer' => $request->input('issuer'),
                'client_id' => $request->input('client_id'),
                'config' => $config,
                'allowed_domains' => $this->normalizeDomains($request->input('allowed_domains', [])),
            ],
        );

        return back()->with('success', __('Single sign-on settings saved.'));
    }

    /**
     * Shape the connection for the page, never exposing the client secret —
     * only whether one is set.
     *
     * @return array<string, mixed>
     */
    private function present(Organization $organization, ?OrganizationSsoConnection $connection): array
    {
        if ($connection === null) {
            return [
                'enabled' => false,
                'auto_provision' => true,
                'issuer' => null,
                'client_id' => null,
                'has_secret' => false,
                'allowed_domains' => [],
                'endpoints' => null,
            ];
        }

        $config = $connection->config ?? [];

        return [
            'enabled' => $connection->enabled,
            'auto_provision' => $connection->auto_provision,
            'issuer' => $connection->issuer,
            'client_id' => $connection->client_id,
            'has_secret' => filled($config['client_secret'] ?? null),
            'allowed_domains' => $connection->allowed_domains ?? [],
            'endpoints' => [
                'authorize_url' => $config['authorize_url'] ?? null,
                'token_url' => $config['token_url'] ?? null,
                'userinfo_url' => $config['userinfo_url'] ?? null,
            ],
        ];
    }

    /**
     * @param  mixed  $domains
     * @return array<int, string>
     */
    private function normalizeDomains($domains): array
    {
        if (! is_array($domains)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($d): string => strtolower(trim((string) $d)),
            $domains,
        ))));
    }
}
