<?php

use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use App\Services\Integrations\Support\SsrfGuard;
use App\Services\Tools\McpAuthResolver;
use App\Services\Tools\McpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->client = new McpClient(
        new McpAuthResolver(new OAuth2TokenRefresher),
        new SsrfGuard,
    );
});

it('lists the tools a server exposes over JSON-RPC', function () {
    Http::fake([
        'https://mcp.example.invalid/sse' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200, ['Mcp-Session-Id' => 'sess-1'])
            ->push('', 202) // notifications/initialized
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                ['name' => 'create_order', 'description' => 'Create an order', 'inputSchema' => ['required' => ['sku']]],
                ['name' => 'get_status', 'description' => 'Check status', 'inputSchema' => []],
            ]]], 200),
    ]);

    $tools = $this->client->listTools(['endpoint' => 'https://mcp.example.invalid/sse', 'auth_type' => 'none']);

    expect($tools)->toHaveCount(2)
        ->and($tools[0]['name'])->toBe('create_order')
        ->and($tools[0]['description'])->toBe('Create an order')
        ->and($tools[0]['input_schema']['required'])->toBe(['sku'])
        ->and($tools[1]['name'])->toBe('get_status');
});

it('parses SSE-framed responses', function () {
    $sse = "event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
        ['name' => 'ping', 'description' => 'Ping', 'inputSchema' => []],
    ]]])."\n\n";

    Http::fake([
        'https://mcp.example.invalid/mcp' => Http::sequence()
            ->push(['result' => []], 200)
            ->push('', 202)
            ->push($sse, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $tools = $this->client->listTools(['endpoint' => 'https://mcp.example.invalid/mcp', 'auth_type' => 'none']);

    expect($tools)->toHaveCount(1)->and($tools[0]['name'])->toBe('ping');
});

it('calls a tool and returns its text content', function () {
    Http::fake([
        'https://mcp.example.invalid/mcp' => Http::sequence()
            ->push(['result' => []], 200, ['Mcp-Session-Id' => 's1'])
            ->push('', 202)
            ->push(['result' => ['content' => [['type' => 'text', 'text' => 'The sum is 42.']]]], 200),
    ]);

    $out = $this->client->callTool(
        ['endpoint' => 'https://mcp.example.invalid/mcp', 'auth_type' => 'none'],
        null,
        'add',
        ['a' => 40, 'b' => 2],
    );

    expect($out)->toBe('The sum is 42.');
});

it('raises a clear error when the server rejects credentials', function () {
    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    $this->client->listTools(['endpoint' => 'https://mcp.example.invalid/mcp', 'auth_type' => 'none']);
})->throws(RuntimeException::class, 're-authorize');

it('surfaces a JSON-RPC error from the server', function () {
    Http::fake([
        'https://mcp.example.invalid/mcp' => Http::sequence()
            ->push(['result' => []], 200)
            ->push('', 202)
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32601, 'message' => 'method not found']], 200),
    ]);

    $this->client->listTools(['endpoint' => 'https://mcp.example.invalid/mcp', 'auth_type' => 'none']);
})->throws(RuntimeException::class, 'method not found');
