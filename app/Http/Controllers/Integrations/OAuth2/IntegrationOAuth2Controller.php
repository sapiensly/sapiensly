<?php

namespace App\Http\Controllers\Integrations\OAuth2;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\Integrations\OAuth2\OAuth2AuthorizationCodeFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles the OAuth2 Authorization Code flow leg that requires the user's
 * browser: the initial redirect to the provider and the callback where the
 * authorization code is exchanged for tokens.
 */
class IntegrationOAuth2Controller extends Controller
{
    public function __construct(
        private OAuth2AuthorizationCodeFlow $flow,
    ) {}

    public function redirect(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorize('update', $integration);

        // Belt-and-braces: validation already enforces these on store, but
        // a bare authorize URL sent to the provider with client_id= or
        // without a matching authorize_url ends in a provider 404, which
        // leaves the user stranded outside the app.
        $cfg = $integration->auth_config ?? [];
        $missing = [];
        foreach (['authorize_url', 'token_url', 'client_id', 'client_secret'] as $field) {
            if (empty($cfg[$field])) {
                $missing[] = $field;
            }
        }

        if (! empty($missing)) {
            return redirect()
                ->route('system.integrations.show', ['integration' => $integration->id])
                ->withErrors([
                    'oauth2' => __('Cannot start authorization — these fields are missing: :fields. Open Edit and fill them in first.', [
                        'fields' => implode(', ', $missing),
                    ]),
                ]);
        }

        $prepared = $this->flow->buildAuthorizeUrl($integration);

        $request->session()->put('integrations.oauth2.state', [
            'integration_id' => $integration->id,
            'state' => $prepared['state'],
            'code_verifier' => $prepared['code_verifier'],
        ]);

        return redirect()->away($prepared['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $error = (string) $request->query('error', '');

        $stored = $request->session()->pull('integrations.oauth2.state');
        if (! is_array($stored) || empty($stored['integration_id'])) {
            abort(400, __('No pending OAuth2 handshake in this session.'));
        }

        $integration = Integration::findOrFail($stored['integration_id']);

        if ($error !== '') {
            return redirect()
                ->route('system.integrations.show', ['integration' => $integration->id])
                ->withErrors(['oauth2' => __('Provider returned error: :error', ['error' => $error])]);
        }

        if ($code === '' || $state === '') {
            abort(400, __('Missing code or state parameter.'));
        }

        $this->flow->handleCallback(
            integration: $integration,
            code: $code,
            stateFromProvider: $state,
            stateExpected: $stored['state'] ?? '',
            codeVerifier: $stored['code_verifier'] ?? null,
        );

        return redirect()
            ->route('system.integrations.show', ['integration' => $integration->id])
            ->with('success', __('OAuth 2.0 authorization completed.'));
    }
}
