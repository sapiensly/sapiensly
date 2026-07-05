<?php

use App\Services\Integrations\OAuth2\OAuth2TokenRefresher;
use App\Services\Integrations\Support\SsrfGuard;
use App\Services\Tools\McpAuthResolver;
use App\Services\Tools\McpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

/**
 * The connected-object read path (callToolData) must recover structured rows
 * however a server frames them — the runtime bug where a live dashboard errored
 * "did not return JSON rows" because the tool wrapped its JSON in structured
 * content / a prose summary / a ```json fence``` that strict json_decode rejected.
 */
function fakeToolCall(array $result): void
{
    Http::fake([
        'https://mcp.example.invalid/mcp' => Http::sequence()
            ->push(['result' => []], 200, ['Mcp-Session-Id' => 's1'])
            ->push('', 202)
            ->push(['result' => $result], 200),
    ]);
}

function callData(McpClient $client): ?array
{
    return $client->callToolData(
        ['endpoint' => 'https://mcp.example.invalid/mcp', 'auth_type' => 'none'],
        null,
        'search_tickets',
        ['limit' => 500],
    );
}

it('reads rows from the MCP spec structuredContent field', function () {
    fakeToolCall([
        'content' => [['type' => 'text', 'text' => 'Found 2 tickets in the last 30 days.']],
        'structuredContent' => ['tickets' => [['id' => 'T1'], ['id' => 'T2']]],
    ]);

    expect(callData($this->client))->toBe(['tickets' => [['id' => 'T1'], ['id' => 'T2']]]);
});

it('reads rows from a bare JSON text block', function () {
    fakeToolCall(['content' => [['type' => 'text', 'text' => json_encode(['tickets' => [['id' => 'T1']]])]]]);

    expect(callData($this->client))->toBe(['tickets' => [['id' => 'T1']]]);
});

it('reads rows from JSON wrapped in a fenced code block', function () {
    $text = "Here are the tickets:\n```json\n".json_encode(['tickets' => [['id' => 'T9']]])."\n```";
    fakeToolCall(['content' => [['type' => 'text', 'text' => $text]]]);

    expect(callData($this->client))->toBe(['tickets' => [['id' => 'T9']]]);
});

it('reads rows from JSON padded with a prose summary', function () {
    $text = 'Summary: 1 open ticket. '.json_encode(['tickets' => [['id' => 'T7']]]).' — end of report.';
    fakeToolCall(['content' => [['type' => 'text', 'text' => $text]]]);

    expect(callData($this->client))->toBe(['tickets' => [['id' => 'T7']]]);
});

it('finds the JSON block among several content blocks', function () {
    fakeToolCall(['content' => [
        ['type' => 'text', 'text' => 'The support desk returned these records:'],
        ['type' => 'text', 'text' => json_encode(['tickets' => [['id' => 'T4']]])],
    ]]);

    expect(callData($this->client))->toBe(['tickets' => [['id' => 'T4']]]);
});

it('returns null when the tool answers in prose with no JSON', function () {
    fakeToolCall(['content' => [['type' => 'text', 'text' => 'No tickets were found for that period.']]]);

    expect(callData($this->client))->toBeNull();
});

it('logs a raw preview when the tool result has no decodable JSON', function () {
    Log::spy();
    fakeToolCall(['content' => [['type' => 'text', 'text' => 'Plain prose, nothing structured here.']]]);

    callData($this->client);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'no decodable JSON')
            && str_contains($context['preview'], 'Plain prose')
            && $context['content_types'] === ['text']);
});

it('surfaces a tool-level isError as the tool error, not as missing JSON', function () {
    fakeToolCall([
        'isError' => true,
        'content' => [['type' => 'text', 'text' => 'Unknown argument "limit" — use "per_page".']],
    ]);

    callData($this->client);
})->throws(RuntimeException::class, 'Unknown argument "limit"');

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
