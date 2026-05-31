<?php

use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('auto-configures an OAuth 2.0 integration from a single URL', function () {
    Http::fake([
        '*/.well-known/oauth-protected-resource' => Http::response([
            'authorization_servers' => ['https://auth.example.invalid'],
        ]),
        '*/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://auth.example.invalid/authorize',
            'token_endpoint' => 'https://auth.example.invalid/token',
            'registration_endpoint' => 'https://auth.example.invalid/register',
        ]),
        'https://auth.example.invalid/register' => Http::response([
            'client_id' => 'cid-1',
            'client_secret' => 'secret-1',
        ], 201),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('system.integrations.oauth2.discover'), [
            'url' => 'https://mcp.example.invalid',
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'dynamically_registered' => true,
            'auth_type' => 'oauth2_auth_code',
        ])
        ->assertJsonPath('auth_config.client_id', 'cid-1');
});

it('flags the integration as MCP and persists it on create', function () {
    Http::fake([
        '*/.well-known/oauth-protected-resource' => Http::response([
            'authorization_servers' => ['https://auth.example.invalid'],
        ]),
        '*/.well-known/oauth-authorization-server' => Http::response([
            'authorization_endpoint' => 'https://auth.example.invalid/authorize',
            'token_endpoint' => 'https://auth.example.invalid/token',
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('system.integrations.oauth2.discover'), [
            'url' => 'https://mcp.example.invalid',
        ])
        ->assertOk()
        ->assertJson(['is_mcp' => true]);

    $this->actingAs($this->user)
        ->post(route('system.integrations.store'), [
            'name' => 'MCP CRM',
            'base_url' => 'https://mcp.example.invalid',
            'is_mcp' => true,
            'auth_type' => 'oauth2_auth_code',
            'auth_config' => [
                'authorize_url' => 'https://auth.example.invalid/authorize',
                'token_url' => 'https://auth.example.invalid/token',
                'client_id' => 'cid',
                'pkce' => true,
            ],
        ])->assertRedirect();

    expect(Integration::where('name', 'MCP CRM')->value('is_mcp'))->toBeTrue();
});

it('returns a 422 with a helpful message when discovery fails', function () {
    Http::fake(['*' => Http::response([], 404)]);

    $this->actingAs($this->user)
        ->postJson(route('system.integrations.oauth2.discover'), [
            'url' => 'https://mcp.example.invalid',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('validates the url is required', function () {
    $this->actingAs($this->user)
        ->postJson(route('system.integrations.oauth2.discover'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('url');
});

it('requires authentication', function () {
    $this->postJson(route('system.integrations.oauth2.discover'), [
        'url' => 'https://mcp.example.invalid',
    ])->assertUnauthorized();
});
