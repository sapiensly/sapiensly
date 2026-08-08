<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\IntegrationAuthType;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Services\Integrations\OAuth2\OAuth2AuthorizationCodeFlow;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-user OAuth 2.0 Authorization Code handshake, entered from the CONNECTION
 * itself.
 *
 * Authorization used to hang off a Tool: to connect an MCP server a person had
 * to invent a tool they never asked for, open it, and authorize there — six
 * screens behind a button that said "Connect HubSpot". The token was never
 * about the tool: IntegrationUserToken is keyed by (user, integration), and the
 * tool only decided where to land afterwards. So the handshake lives here and
 * the tool page is now just one possible `return_to`.
 *
 * The callback leg is SHARED with the tools entry point (same registered
 * redirect_uri — providers have that URL on file, so it cannot move).
 */
class IntegrationOAuth2Controller extends Controller
{
    /** Session key holding the in-flight handshake. Shared with ToolOAuth2Controller. */
    public const STATE_KEY = 'integrations.oauth2.state';

    public function __construct(
        private OAuth2AuthorizationCodeFlow $flow,
        private OAuth2TokenRefresher $refresher,
    ) {}

    /**
     * Send the current user to the provider to authorize their OWN account.
     *
     * Gated by `connect`, not `update`: connecting your own account is not
     * administering the connection, and the people who need this (the technician
     * opening a built app, the builder's provisioning card) hold no integrations
     * permission at all.
     */
    public function redirect(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorize('connect', $integration);

        $fallback = route('system.integrations.show', $integration);
        $returnTo = $this->safeReturnTo($request->query('return_to'), $fallback);

        $authType = $integration->auth_type instanceof IntegrationAuthType
            ? $integration->auth_type
            : IntegrationAuthType::tryFrom((string) $integration->auth_type);

        if ($authType !== IntegrationAuthType::OAuth2AuthorizationCode) {
            return redirect()->to($returnTo)->withErrors([
                'oauth2' => __('This connection does not use OAuth 2.0 authorization code.'),
            ]);
        }

        $cfg = $integration->auth_config ?? [];
        $required = ['authorize_url', 'token_url', 'client_id'];
        if (empty($cfg['pkce'])) {
            $required[] = 'client_secret';
        }
        $missing = array_values(array_filter($required, fn (string $f): bool => empty($cfg[$f])));

        if ($missing !== []) {
            return redirect()->to($returnTo)->withErrors([
                'oauth2' => __('The integration ":name" is missing OAuth fields: :fields.', [
                    'name' => $integration->name,
                    'fields' => implode(', ', $missing),
                ]),
            ]);
        }

        $prepared = $this->flow->buildAuthorizeUrl($integration);

        $request->session()->put(self::STATE_KEY, [
            'integration_id' => $integration->id,
            'user_id' => $request->user()->id,
            'state' => $prepared['state'],
            'code_verifier' => $prepared['code_verifier'],
            'return_to' => $returnTo,
        ]);

        return redirect()->away($prepared['url']);
    }

    /**
     * The shared callback leg. Exchanges the code for the CURRENT user's tokens
     * and returns them wherever the handshake started.
     */
    public function callback(Request $request): RedirectResponse
    {
        $stored = $request->session()->pull(self::STATE_KEY);
        if (! is_array($stored) || empty($stored['integration_id'])) {
            abort(400, __('No pending OAuth 2.0 handshake in this session.'));
        }

        $integration = Integration::findOrFail($stored['integration_id']);
        $returnTo = $this->safeReturnTo(
            $stored['return_to'] ?? null,
            route('system.integrations.show', $integration),
        );

        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return redirect()->to($returnTo)->withErrors([
                'oauth2' => __('Provider returned error: :error', ['error' => $error]),
            ]);
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || $state === '') {
            abort(400, __('Missing code or state parameter.'));
        }

        if (! hash_equals((string) ($stored['state'] ?? ''), $state)) {
            abort(400, __('OAuth 2.0 state mismatch — possible CSRF.'));
        }

        $tokens = $this->refresher->requestWithAuthorizationCode(
            $integration->auth_config ?? [],
            $code,
            $stored['code_verifier'] ?? null,
        );

        IntegrationUserToken::updateOrCreate(
            ['user_id' => $stored['user_id'], 'integration_id' => $integration->id],
            ['auth_config' => $tokens],
        );

        return redirect()->to($returnTo)->with('success', __('Authorization completed.'));
    }

    /**
     * Only ever return to a path on THIS host. The caller passes `return_to` so
     * the handshake can land back in the app the person was actually using, and
     * an unchecked value there is an open redirect on an authenticated route.
     * Protocol-relative ("//evil.test") is rejected along with absolute URLs.
     */
    private function safeReturnTo(mixed $candidate, string $fallback): string
    {
        if (! is_string($candidate) || $candidate === '') {
            return $fallback;
        }

        if (! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        return $candidate;
    }
}
