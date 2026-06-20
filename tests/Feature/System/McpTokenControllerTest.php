<?php

use App\Enums\MembershipRole;
use App\Models\McpAccessToken;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Org-level MCP token management lives in the System area and is owner-gated.
 * Tokens are bound to the organization. (mcpOrg/mcpMember helpers come from the
 * Mcp test suite.)
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

it('lets an owner view the org MCP page', function () {
    $org = mcpOrg();
    $owner = mcpMember($org, MembershipRole::Owner);

    actingAs($owner)->get('/system/mcp')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('system/McpTokens')->has('serverUrl'));
});

it('forbids a non-owner member', function () {
    $org = mcpOrg();
    $member = mcpMember($org, MembershipRole::Member);

    actingAs($member)->get('/system/mcp')->assertForbidden();
});

it('lets an owner create an org-bound token and flashes it once', function () {
    $org = mcpOrg();
    $owner = mcpMember($org, MembershipRole::Owner);

    actingAs($owner)->post('/system/mcp', ['name' => 'CI', 'abilities' => ['data:read']])
        ->assertRedirect()
        ->assertSessionHas('plain_token');

    $token = McpAccessToken::where('organization_id', $org->id)->firstOrFail();
    expect($token->user_id)->toBe($owner->id)
        ->and($token->organization_id)->toBe($org->id)
        ->and($token->abilities)->toBe(['data:read']);
});

it('forbids a non-owner from creating a token', function () {
    $org = mcpOrg();
    $member = mcpMember($org, MembershipRole::Member);

    actingAs($member)->post('/system/mcp', ['name' => 'x'])->assertForbidden();
    expect(McpAccessToken::count())->toBe(0);
});

it('revokes a token in the owner org but not another org', function () {
    $org = mcpOrg();
    $owner = mcpMember($org, MembershipRole::Owner);
    $mine = McpAccessToken::create([
        'user_id' => $owner->id, 'organization_id' => $org->id, 'name' => 't', 'token' => McpAccessToken::generateToken(),
    ]);

    $otherOrg = mcpOrg('Other');
    $otherOwner = mcpMember($otherOrg, MembershipRole::Owner);
    $foreign = McpAccessToken::create([
        'user_id' => $otherOwner->id, 'organization_id' => $otherOrg->id, 'name' => 't', 'token' => McpAccessToken::generateToken(),
    ]);

    actingAs($owner)->delete("/system/mcp/{$mine->id}")->assertRedirect();
    expect(McpAccessToken::find($mine->id))->toBeNull();

    actingAs($owner)->delete("/system/mcp/{$foreign->id}")->assertForbidden();
    expect(McpAccessToken::find($foreign->id))->not->toBeNull();
});
