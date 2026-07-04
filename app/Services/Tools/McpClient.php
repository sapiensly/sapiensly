<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Model Context Protocol client over Streamable HTTP. Performs the
 * JSON-RPC handshake (initialize → initialized) and lists the tools a server
 * exposes. Authentication headers come from McpAuthResolver, so OAuth tools
 * use the current user's token.
 */
class McpClient
{
    private const TIMEOUT_SECONDS = 20;

    private const CONNECT_TIMEOUT_SECONDS = 6;

    private const PROTOCOL_VERSION = '2025-06-18';

    /**
     * initialize→initialized handshake results, keyed by endpoint+auth. The
     * builder samples an MCP source across SEVERAL calls in one turn (list the
     * tools, then call one); reusing the session skips a full re-handshake
     * (initialize + initialized, up to ~40s) on every call after the first.
     * This instance is held for the lifetime of ONE builder turn, so the cache
     * never crosses turns or users.
     *
     * @var array<string, string>
     */
    private array $sessions = [];

    public function __construct(
        private readonly McpAuthResolver $authResolver,
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Return a live MCP session for this endpoint+auth, performing the
     * initialize→initialized handshake once and caching the session id.
     *
     * @param  array<string, string>  $authHeaders
     */
    private function session(string $endpoint, array $authHeaders): string
    {
        $key = md5($endpoint.'|'.json_encode($authHeaders));
        if (isset($this->sessions[$key])) {
            return $this->sessions[$key];
        }

        $init = $this->rpc($endpoint, $authHeaders, null, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => config('app.name', 'Sapiensly'), 'version' => '1.0.0'],
        ], 1);
        $session = (string) $init['session'];

        // Tell the server initialization finished (notification, no response).
        $this->notify($endpoint, $authHeaders, $session, 'notifications/initialized');

        return $this->sessions[$key] = $session;
    }

    /**
     * Connect to the MCP server and return the tools it exposes.
     *
     * @param  array<string, mixed>  $config  Decrypted MCP tool config (endpoint, auth, integration_id).
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     *
     * @throws \RuntimeException On connection, auth, or protocol failure.
     */
    public function listTools(array $config, ?User $user = null): array
    {
        $endpoint = (string) ($config['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new \RuntimeException('This MCP tool has no server endpoint.');
        }

        $this->ssrfGuard->assertHostAllowed($endpoint);

        $authHeaders = $this->authResolver->resolveHeaders($config, $user);

        $session = $this->session($endpoint, $authHeaders);

        $list = $this->rpc($endpoint, $authHeaders, $session, 'tools/list', new \stdClass, 2);

        $tools = $list['result']['tools'] ?? [];
        if (! is_array($tools)) {
            return [];
        }

        return array_values(array_map(fn (array $tool): array => [
            'name' => (string) ($tool['name'] ?? ''),
            'description' => (string) ($tool['description'] ?? ''),
            'input_schema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [],
        ], array_filter($tools, fn ($t): bool => is_array($t) && ! empty($t['name']))));
    }

    /**
     * Call a tool on the MCP server and return its result as text.
     *
     * @param  array<string, mixed>  $config  Decrypted MCP tool config.
     * @param  array<string, mixed>  $arguments
     *
     * @throws \RuntimeException On connection, auth, or protocol failure.
     */
    public function callTool(array $config, ?User $user, string $name, array $arguments): string
    {
        $endpoint = (string) ($config['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new \RuntimeException('This MCP tool has no server endpoint.');
        }

        $this->ssrfGuard->assertHostAllowed($endpoint);
        $authHeaders = $this->authResolver->resolveHeaders($config, $user);

        $session = $this->session($endpoint, $authHeaders);

        $call = $this->rpc($endpoint, $authHeaders, $session, 'tools/call', [
            'name' => $name,
            'arguments' => empty($arguments) ? new \stdClass : $arguments,
        ], 3);

        return $this->stringifyToolResult($call['result']);
    }

    /**
     * Flatten an MCP tools/call result (content blocks) into plain text.
     *
     * @param  array<string, mixed>  $result
     */
    private function stringifyToolResult(array $result): string
    {
        $content = $result['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (! is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                    $parts[] = (string) $block['text'];
                } else {
                    $parts[] = json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
            $text = trim(implode("\n", array_filter($parts)));
            if ($text !== '') {
                return mb_substr($text, 0, 12000);
            }
        }

        return mb_substr(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', 0, 12000);
    }

    /**
     * Send a JSON-RPC request and return its parsed result + any session id.
     *
     * @param  array<string, string>  $authHeaders
     * @param  array<string, mixed>|\stdClass  $params
     * @return array{result: array<string, mixed>, session: ?string}
     */
    private function rpc(string $endpoint, array $authHeaders, ?string $session, string $method, array|\stdClass $params, int $id): array
    {
        $response = $this->send($endpoint, $authHeaders, $session, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException('The MCP server rejected the credentials — re-authorize this tool.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException("MCP server returned HTTP {$response->status()} for {$method}.");
        }

        $payload = $this->decode($response);

        if (isset($payload['error'])) {
            $message = $payload['error']['message'] ?? 'unknown error';
            throw new \RuntimeException("MCP server error on {$method}: {$message}");
        }

        return [
            'result' => is_array($payload['result'] ?? null) ? $payload['result'] : [],
            'session' => $response->header('Mcp-Session-Id') ?: $session,
        ];
    }

    /**
     * @param  array<string, string>  $authHeaders
     */
    private function notify(string $endpoint, array $authHeaders, ?string $session, string $method): void
    {
        try {
            $this->send($endpoint, $authHeaders, $session, [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => new \stdClass,
            ]);
        } catch (\Throwable) {
            // Notifications are best-effort; a server that ignores them still
            // answers tools/list.
        }
    }

    /**
     * @param  array<string, string>  $authHeaders
     * @param  array<string, mixed>  $body
     */
    private function send(string $endpoint, array $authHeaders, ?string $session, array $body): Response
    {
        $headers = array_merge($authHeaders, [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ]);
        if ($session !== null && $session !== '') {
            $headers['Mcp-Session-Id'] = $session;
        }

        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->withHeaders($headers)
            ->post($endpoint, $body);
    }

    /**
     * Decode a JSON-RPC response that may be plain JSON or SSE-framed.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $contentType = strtolower($response->header('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            return $this->decodeEventStream($response->body());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Pull the last JSON-RPC message out of an SSE response body.
     *
     * @return array<string, mixed>
     */
    private function decodeEventStream(string $body): array
    {
        $last = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '') {
                continue;
            }
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $last = $decoded;
            }
        }

        return $last;
    }
}
