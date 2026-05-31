<?php

use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create(['organization_id' => null]);
});

function mcpOAuthTool(User $user, Integration $integration): Tool
{
    return Tool::factory()->mcp()->create([
        'user_id' => $user->id,
        'config' => [
            'endpoint' => 'https://mcp.example.com/sse',
            'auth_type' => 'oauth2',
            'integration_id' => $integration->id,
        ],
    ]);
}

test('authorize redirects the user to the provider and stashes per-user state', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();
    $tool = mcpOAuthTool($this->user, $integration);

    actingAs($this->user)
        ->get(route('tools.oauth2.authorize', $tool))
        ->assertRedirect()
        ->assertRedirectContains('https://auth.example.com/oauth/authorize');

    expect(session('tools.oauth2.state.tool_id'))->toBe($tool->id)
        ->and(session('tools.oauth2.state.integration_id'))->toBe($integration->id)
        ->and(session('tools.oauth2.state.user_id'))->toBe($this->user->id);
});

test('authorize works for a public PKCE client without a client_secret', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create([
        'auth_config' => [
            'authorize_url' => 'https://auth.example.com/oauth/authorize',
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => 'public-client',
            'client_secret' => '',
            'redirect_uri' => 'https://app.test/oauth/integrations/callback',
            'pkce' => true,
        ],
    ]);
    $tool = mcpOAuthTool($this->user, $integration);

    actingAs($this->user)
        ->get(route('tools.oauth2.authorize', $tool))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('https://auth.example.com/oauth/authorize');
});

test('callback exchanges the code and stores tokens for the current user', function () {
    Http::fake([
        'https://auth.example.com/oauth/token' => Http::response([
            'access_token' => 'user-access',
            'refresh_token' => 'user-refresh',
            'expires_in' => 3600,
        ], 200),
    ]);

    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();
    $tool = mcpOAuthTool($this->user, $integration);

    actingAs($this->user)
        ->withSession(['tools.oauth2.state' => [
            'tool_id' => $tool->id,
            'integration_id' => $integration->id,
            'user_id' => $this->user->id,
            'state' => 'st4te',
            'code_verifier' => 'verifier',
        ]])
        ->get(route('integrations.oauth2.callback', ['code' => 'prov-code', 'state' => 'st4te']))
        ->assertRedirect(route('tools.show', $tool));

    $token = IntegrationUserToken::where('user_id', $this->user->id)
        ->where('integration_id', $integration->id)
        ->firstOrFail();

    expect($token->auth_config['access_token'])->toBe('user-access')
        ->and($token->auth_config['refresh_token'])->toBe('user-refresh');

    // The shared integration must NOT have received the user's token.
    expect($integration->fresh()->auth_config['access_token'] ?? null)->toBeNull();
});

test('callback rejects a state mismatch', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();
    $tool = mcpOAuthTool($this->user, $integration);

    actingAs($this->user)
        ->withSession(['tools.oauth2.state' => [
            'tool_id' => $tool->id,
            'integration_id' => $integration->id,
            'user_id' => $this->user->id,
            'state' => 'expected',
            'code_verifier' => 'v',
        ]])
        ->get(route('integrations.oauth2.callback', ['code' => 'c', 'state' => 'tampered']))
        ->assertStatus(400);
});

test('authorize redirects back with an error when the integration client config is incomplete', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create([
        'auth_config' => [
            'authorize_url' => 'https://auth.example.com/oauth/authorize',
            'token_url' => 'https://auth.example.com/oauth/token',
            'client_id' => '',
            'client_secret' => '',
            'pkce' => true,
        ],
    ]);
    $tool = mcpOAuthTool($this->user, $integration);

    actingAs($this->user)
        ->get(route('tools.oauth2.authorize', $tool))
        ->assertRedirect(route('tools.show', $tool))
        ->assertSessionHasErrors('oauth2');
});

test('the tool page reports per-user connection status', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();
    $tool = mcpOAuthTool($this->user, $integration);

    actingAs($this->user)
        ->get(route('tools.show', $tool))
        ->assertInertia(fn ($page) => $page
            ->where('mcpAuthorization.connected', false)
            ->where('mcpAuthorization.authorize_url', route('tools.oauth2.authorize', $tool))
        );

    IntegrationUserToken::create([
        'user_id' => $this->user->id,
        'integration_id' => $integration->id,
        'auth_config' => ['access_token' => 'tok', 'expires_at' => time() + 3600],
    ]);

    actingAs($this->user)
        ->get(route('tools.show', $tool))
        ->assertInertia(fn ($page) => $page->where('mcpAuthorization.connected', true));
});

test('a member cannot authorize a tool they do not own', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create();
    $tool = mcpOAuthTool($this->user, $integration);
    $other = User::factory()->create(['organization_id' => null]);

    actingAs($other)
        ->get(route('tools.oauth2.authorize', $tool))
        ->assertForbidden();
});
