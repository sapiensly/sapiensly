<?php

namespace App\Services\Tools;

use App\Models\User;
use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function callTool(array $config, ?User $user, string $name, array $arguments, int $maxChars = 12000): string
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

        return $this->stringifyToolResult($call['result'], $maxChars);
    }

    /**
     * Call an MCP tool and return its result as decoded structured data — the
     * MACHINE path (connected-object reads), where `callTool`'s flattened text
     * is meant for a human/model to read. Tolerant of how a server frames its
     * rows: the spec's `structuredContent`, a JSON text block (bare or fenced in
     * a ```json block), or JSON padded with a prose summary. Returns null only
     * when the tool answered in prose with no JSON at all — the caller then
     * reports "did not return JSON rows" rather than silently showing nothing.
     *
     * @param  array<string, mixed>  $config  Decrypted MCP tool config.
     * @param  array<string, mixed>  $arguments
     * @return array<mixed>|null
     *
     * @throws \RuntimeException On connection, auth, or protocol failure.
     */
    public function callToolData(array $config, ?User $user, string $name, array $arguments, int $maxChars = 2_000_000): ?array
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

        // A tool-level failure (isError) arrives as a SUCCESSFUL call whose
        // content is the error prose — e.g. the tool rejecting an argument.
        // Surface that message; treating it as data would mis-report it as
        // "did not return JSON rows" and hide the actual cause.
        if (($call['result']['isError'] ?? false) === true) {
            $message = trim($this->stringifyToolResult($call['result'], 500));

            throw new \RuntimeException("The MCP tool '{$name}' returned an error: ".($message !== '' ? $message : 'unknown error'));
        }

        return $this->decodeToolData($call['result'], $maxChars);
    }

    /**
     * Extract structured rows from a tools/call result, tolerant of framing:
     * (1) the MCP spec's `structuredContent`; (2) a `text` content block that is
     * JSON — bare or wrapped in a ```json fence``` / prose; (3) the flattened
     * text as a whole. Null when no JSON is present anywhere.
     *
     * @param  array<string, mixed>  $result
     * @return array<mixed>|null
     */
    private function decodeToolData(array $result, int $maxChars): ?array
    {
        if (isset($result['structuredContent']) && is_array($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        foreach ($result['content'] ?? [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text' || ! isset($block['text'])) {
                continue;
            }
            $decoded = $this->decodeJsonLoose(mb_substr((string) $block['text'], 0, $maxChars));
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $flattened = $this->stringifyToolResult($result, $maxChars);
        $decoded = $this->decodeJsonLoose($flattened);

        // No JSON anywhere: log what the tool ACTUALLY said so the failure is
        // diagnosable from production logs (the block error alone — "did not
        // return JSON rows" — says nothing about the response's real shape).
        if ($decoded === null) {
            Log::warning('MCP tool result had no decodable JSON', [
                'result_keys' => array_keys($result),
                'content_types' => array_values(array_map(
                    fn ($b) => is_array($b) ? ($b['type'] ?? 'unknown') : gettype($b),
                    is_array($result['content'] ?? null) ? $result['content'] : [],
                )),
                'flattened_chars' => mb_strlen($flattened),
                'preview' => mb_substr($flattened, 0, 400),
            ]);
        }

        return $decoded;
    }

    /**
     * Decode JSON that may be padded: try the whole string, then a ```json
     * fenced``` span, then the outermost {…}/[…] region. Null if none parses.
     *
     * @return array<mixed>|null
     */
    private function decodeJsonLoose(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $text, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $close = $text[$start] === '{' ? '}' : ']';
            $end = strrpos($text, $close);
            if ($end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * Flatten an MCP tools/call result (content blocks) into plain text, capped
     * at $maxChars. Sampling passes the small default (a preview for the model);
     * the connected-object reader passes a larger bound so a data list isn't
     * truncated mid-row — still capped, to keep a runaway response off the heap.
     *
     * @param  array<string, mixed>  $result
     */
    private function stringifyToolResult(array $result, int $maxChars = 12000): string
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
                return mb_substr($text, 0, $maxChars);
            }
        }

        return mb_substr(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', 0, $maxChars);
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
