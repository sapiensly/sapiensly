<?php

use App\Models\McpAccessToken;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Self-service MCP token management: a user creates tokens (raw value shown once),
 * sees only their own, and can revoke them but not anyone else's.
 */
it('renders the MCP tokens settings page', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    actingAs($user)->get('/settings/mcp')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/McpTokens')->has('abilities')->has('serverUrl'));
});

it('creates a token and flashes the raw value once', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = actingAs($user)->post('/settings/mcp', [
        'name' => 'My laptop',
        'abilities' => ['data:read', 'agents:invoke'],
    ]);

    $response->assertRedirect()->assertSessionHas('plain_token');

    $token = McpAccessToken::where('user_id', $user->id)->firstOrFail();
    expect($token->name)->toBe('My laptop')
        ->and($token->abilities)->toBe(['data:read', 'agents:invoke'])
        ->and(session('plain_token'))->toBe($token->token);
});

it('treats no selected abilities as all abilities (null)', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    actingAs($user)->post('/settings/mcp', ['name' => 'Full', 'abilities' => []]);

    expect(McpAccessToken::where('user_id', $user->id)->value('abilities'))->toBeNull();
});

it('rejects an unknown ability', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    actingAs($user)->post('/settings/mcp', ['name' => 'Bad', 'abilities' => ['root:everything']])
        ->assertSessionHasErrors('abilities.0');
});

it('revokes the user own token', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $token = McpAccessToken::create([
        'user_id' => $user->id, 'name' => 't', 'token' => McpAccessToken::generateToken(),
    ]);

    actingAs($user)->delete("/settings/mcp/{$token->id}")->assertRedirect();

    expect(McpAccessToken::find($token->id))->toBeNull();
});

it('cannot revoke another users token', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $other = User::factory()->create(['email_verified_at' => now()]);
    $token = McpAccessToken::create([
        'user_id' => $owner->id, 'name' => 't', 'token' => McpAccessToken::generateToken(),
    ]);

    actingAs($other)->delete("/settings/mcp/{$token->id}")->assertForbidden();

    expect(McpAccessToken::find($token->id))->not->toBeNull();
});
