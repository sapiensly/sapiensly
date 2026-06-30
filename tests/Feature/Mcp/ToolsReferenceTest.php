<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Integrations\ToolsReferenceTool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('tools_reference lists its topics when called with no topic', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ToolsReferenceTool::class, [])
        ->assertOk()
        ->assertSee('connection_vs_action')
        ->assertSee('rest_api')
        ->assertSee('integration');
});

it('tools_reference returns the rest_api config shape', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ToolsReferenceTool::class, ['topic' => 'rest_api'])
        ->assertOk()
        ->assertSee('integration_id')
        ->assertSee('method');
});

it('tools_reference returns auth_config shapes for integrations', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ToolsReferenceTool::class, ['topic' => 'integration'])
        ->assertOk()
        ->assertSee('oauth2_auth_code')
        ->assertSee('auth_config_by_type');
});

it('tools_reference reports an unknown topic', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ToolsReferenceTool::class, ['topic' => 'nope'])
        ->assertOk()
        ->assertSee('Unknown topic');
});
