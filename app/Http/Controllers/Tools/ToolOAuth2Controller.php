<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integrations\IntegrationOAuth2Controller;
use App\Models\Integration;
use App\Models\Tool;
use App\Services\Integrations\OAuth2\OAuth2AuthorizationCodeFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The tool-page entry point into the per-user OAuth 2.0 handshake. It only
 * resolves the tool's integration and records where to land afterwards — the
 * handshake itself (and the shared callback leg) lives in
 * IntegrationOAuth2Controller, because the token it produces belongs to the
 * (user, integration) pair and never to the tool.
 */
class ToolOAuth2Controller extends Controller
{
    public function __construct(
        private OAuth2AuthorizationCodeFlow $flow,
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

        $request->session()->put(IntegrationOAuth2Controller::STATE_KEY, [
            'integration_id' => $integration->id,
            'user_id' => $request->user()->id,
            'state' => $prepared['state'],
            'code_verifier' => $prepared['code_verifier'],
            'return_to' => route('tools.show', $tool, absolute: false),
        ]);

        return redirect()->away($prepared['url']);
    }
}
