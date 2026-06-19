<?php

use App\Models\McpAccessToken;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * The MCP endpoint is bearer-token protected: no token (or a bad/expired one) is
 * rejected before any tool runs, and a valid token establishes the owning user's
 * tenant scope.
 */
it('rejects an MCP request with no bearer token', function () {
    $this->postJson('/mcp/v1', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401);
});

it('rejects an invalid bearer token', function () {
    $this->withToken('not-a-real-token')
        ->postJson('/mcp/v1', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401);
});

it('rejects an expired token', function () {
    $user = User::factory()->create();
    $plain = McpAccessToken::generateToken();
    McpAccessToken::create([
        'user_id' => $user->id,
        'name' => 'old',
        'token' => $plain,
        'expires_at' => now()->subDay(),
    ]);

    $this->withToken($plain)
        ->postJson('/mcp/v1', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401);
});

it('authenticates a valid token and binds the tenant scope', function () {
    $user = User::factory()->create();
    $plain = McpAccessToken::generateToken();
    McpAccessToken::create(['user_id' => $user->id, 'name' => 'cc', 'token' => $plain]);

    $this->withToken($plain)
        ->postJson('/mcp/v1', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk();

    expect(app(TenantContext::class)->userId())->toBe($user->id);
});
