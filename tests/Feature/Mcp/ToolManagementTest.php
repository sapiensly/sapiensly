<?php

use App\Enums\AgentStatus;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Integrations\CreateToolTool;
use App\Mcp\Tools\Integrations\DeleteToolTool;
use App\Mcp\Tools\Integrations\GetToolTool;
use App\Mcp\Tools\Integrations\UpdateToolTool;
use App\Models\Tool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('create_tool creates a draft tool and encrypts sensitive config', function () {
    $response = SapiensServer::actingAs($this->user)
        ->tool(CreateToolTool::class, [
            'type' => 'rest_api',
            'name' => 'Order lookup',
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'GET',
                'auth_type' => 'bearer',
                'auth_config' => ['token' => 'super-secret-token'],
            ],
        ]);

    $response->assertOk()
        ->assertSee('Order lookup')
        ->assertSee('draft')
        ->assertSee('auth_config_is_set'); // masked, not plaintext

    $tool = Tool::where('user_id', $this->user->id)->where('name', 'Order lookup')->first();
    expect($tool)->not->toBeNull();
    expect($tool->status)->toBe(AgentStatus::Draft);
    // Secret is encrypted at rest, never stored as plaintext.
    expect($tool->config['auth_config'])->not->toBe(['token' => 'super-secret-token']);
    expect(json_encode($tool->config))->not->toContain('super-secret-token');
});

it('get_tool returns the masked config and resolved contract', function () {
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'rest_api',
        'name' => 'Weather',
        'config' => ['base_url' => 'https://wx.example.com', 'method' => 'GET', 'auth_type' => 'none'],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(GetToolTool::class, ['tool_id' => $tool->id])
        ->assertOk()
        ->assertSee('Weather')
        ->assertSee('contract');
});

it('update_tool applies a partial update without nulling other fields', function () {
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'rest_api',
        'name' => 'Original',
        'status' => AgentStatus::Draft,
        'config' => ['base_url' => 'https://x.example.com', 'method' => 'GET', 'auth_type' => 'none'],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(UpdateToolTool::class, ['tool_id' => $tool->id, 'status' => 'active'])
        ->assertOk()
        ->assertSee('active')
        ->assertSee('Original');

    $tool->refresh();
    expect($tool->status)->toBe(AgentStatus::Active);
    expect($tool->name)->toBe('Original'); // untouched
});

it('delete_tool removes a tool in the caller context', function () {
    $tool = Tool::factory()->create(['user_id' => $this->user->id]);

    SapiensServer::actingAs($this->user)
        ->tool(DeleteToolTool::class, ['tool_id' => $tool->id])
        ->assertOk()
        ->assertSee('deleted');

    expect(Tool::find($tool->id))->toBeNull();
});

it('update_tool will not touch a tool outside the caller context', function () {
    $other = Tool::factory()->create(); // a different account's tool

    SapiensServer::actingAs($this->user)
        ->tool(UpdateToolTool::class, ['tool_id' => $other->id, 'name' => 'Hijacked'])
        ->assertHasErrors();

    expect($other->fresh()->name)->not->toBe('Hijacked');
});
