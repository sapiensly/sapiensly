<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\Tool;
use App\Services\Integrations\OAuth2\OAuth2AuthorizationCodeFlow;
use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-user OAuth 2.0 Authorization Code flow for MCP tools. The integration
 * holds the shared client configuration; this flow authorizes the *current
 * user* and stores their tokens in IntegrationUserToken, so members never
 * share an access token. Authorization happens here (Tools), never on the
 * org-level integration page.
 */
class ToolOAuth2Controller extends Controller
{
    public function __construct(
        private OAuth2AuthorizationCodeFlow $flow,
        private OAuth2TokenRefresher $refresher,
    ) {}

    public function redirect(Request $request, Tool $tool): RedirectResponse
    {
        $this->authorize('update', $tool);

        $config = $tool->config ?? [];
        if (($config['auth_type'] ?? null) !== 'oauth2' || empty($config['integration_id'])) {
            return redirect()
                ->route('tools.show', $tool)
                ->withErrors(['oauth2' => __('This tool is not configured for OAuth 2.0.')]);
        }

        $integration = Integration::find($config['integration_id']);
        if (! $integration instanceof Integration) {
            return redirect()
                ->route('tools.show', $tool)
                ->withErrors(['oauth2' => __('The linked integration no longer exists.')]);
        }

        $cfg = $integration->auth_config ?? [];
        $required = ['authorize_url', 'token_url', 'client_id'];
        if (empty($cfg['pkce'])) {
            $required[] = 'client_secret';
        }
        $missing = array_values(array_filter($required, fn (string $f): bool => empty($cfg[$f])));

        if (! empty($missing)) {
            return redirect()
                ->route('tools.show', $tool)
                ->withErrors([
                    'oauth2' => __('The integration ":name" is missing OAuth fields: :fields.', [
                        'name' => $integration->name,
                        'fields' => implode(', ', $missing),
                    ]),
                ]);
        }

        $prepared = $this->flow->buildAuthorizeUrl($integration);

        $request->session()->put('tools.oauth2.state', [
            'tool_id' => $tool->id,
            'integration_id' => $integration->id,
            'user_id' => $request->user()->id,
            'state' => $prepared['state'],
            'code_verifier' => $prepared['code_verifier'],
        ]);

        return redirect()->away($prepared['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $stored = $request->session()->pull('tools.oauth2.state');
        if (! is_array($stored) || empty($stored['tool_id']) || empty($stored['integration_id'])) {
            abort(400, __('No pending OAuth 2.0 handshake in this session.'));
        }

        $tool = Tool::findOrFail($stored['tool_id']);
        $integration = Integration::findOrFail($stored['integration_id']);

        $error = (string) $request->query('error', '');
        if ($error !== '') {
            return redirect()
                ->route('tools.show', $tool)
                ->withErrors(['oauth2' => __('Provider returned error: :error', ['error' => $error])]);
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

        return redirect()
            ->route('tools.show', $tool)
            ->with('success', __('Authorization completed.'));
    }
}
