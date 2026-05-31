<?php

use App\Models\Tool;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function mcpServerFake(): void
{
    Http::fake([
        'https://mcp.example.invalid/sse' => Http::sequence()
            ->push(['result' => []], 200, ['Mcp-Session-Id' => 's1'])
            ->push('', 202)
            ->push(['result' => ['tools' => [
                ['name' => 'create_order', 'description' => 'Create an order', 'inputSchema' => ['required' => ['sku']]],
            ]]], 200),
    ]);
}

test('refresh fetches the server tools and caches them on the tool', function () {
    mcpServerFake();

    $tool = Tool::factory()->mcp()->create([
        'user_id' => $this->user->id,
        'config' => ['endpoint' => 'https://mcp.example.invalid/sse', 'auth_type' => 'none', 'auth_config' => []],
    ]);

    actingAs($this->user)
        ->postJson(route('tools.mcp.refresh', $tool))
        ->assertOk()
        ->assertJsonPath('tools.0.name', 'create_order')
        ->assertJsonPath('tools.0.input_schema.required.0', 'sku');

    expect($tool->fresh()->config['mcp_tools'][0]['name'])->toBe('create_order')
        ->and($tool->fresh()->config)->toHaveKey('mcp_tools_synced_at');
});

test('refresh returns a 422 with a message when the server fails', function () {
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

    $tool = Tool::factory()->mcp()->create([
        'user_id' => $this->user->id,
        'config' => ['endpoint' => 'https://mcp.example.invalid/sse', 'auth_type' => 'none', 'auth_config' => []],
    ]);

    actingAs($this->user)
        ->postJson(route('tools.mcp.refresh', $tool))
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

test('a member cannot refresh a tool they cannot view', function () {
    $tool = Tool::factory()->mcp()->create([
        'user_id' => $this->user->id,
        'config' => ['endpoint' => 'https://mcp.example.invalid/sse', 'auth_type' => 'none', 'auth_config' => []],
    ]);
    $other = User::factory()->create(['organization_id' => null]);

    actingAs($other)
        ->postJson(route('tools.mcp.refresh', $tool))
        ->assertForbidden();
});
