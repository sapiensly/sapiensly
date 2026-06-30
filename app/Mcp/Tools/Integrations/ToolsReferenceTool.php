<?php

namespace App\Mcp\Tools\Integrations;

use App\Mcp\Tools\SapiensTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Reference for authoring tools (connectors) and integrations (connections): the config shape per tool type, the auth_config shape per integration auth_type, and how read/write effect gating works. Call with no topic to list topics, then call again with a topic.')]
class ToolsReferenceTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $topic = trim((string) $request->get('topic', ''));
        $topics = $this->topics();

        if ($topic === '') {
            return Response::json([
                'topics' => array_keys($topics),
                'next' => 'Call tools_reference again with topic=<one of the topics above>.',
            ]);
        }

        if (! isset($topics[$topic])) {
            return Response::json([
                'error' => "Unknown topic '{$topic}'.",
                'topics' => array_keys($topics),
            ]);
        }

        return Response::json(['topic' => $topic, 'reference' => $topics[$topic]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function topics(): array
    {
        return [
            'connection_vs_action' => [
                'summary' => 'An Integration is the CONNECTION (base URL + auth, shared and reusable). A Tool is one ACTION (a single operation). A connected tool references its integration via config.integration_id and borrows the base URL + auth from it; a self-contained (legacy) tool carries base_url + auth_config inline. Prefer connected tools: create_integration once, then create_tool with config.integration_id.',
                'flow' => 'create_integration → test_integration_connection → create_tool (config.integration_id) → use_tool / bind to an agent.',
            ],
            'rest_api' => [
                'summary' => 'One HTTP request. Connected mode borrows base_url + auth from an integration; inline mode carries them itself.',
                'config_connected' => [
                    'integration_id' => 'id of an http-kind integration (provides base_url + auth)',
                    'method' => 'GET | POST | PUT | PATCH | DELETE (required)',
                    'path' => 'path appended to the integration base_url, e.g. /orders/{{order_id}}',
                    'headers' => 'object of extra headers (optional)',
                    'request_body_template' => 'string/JSON template with {{placeholders}} (optional)',
                    'response_mapping' => 'object mapping result keys to dot-paths in the response (optional)',
                ],
                'config_inline' => [
                    'base_url' => 'required when no integration_id; e.g. https://api.example.com',
                    'method' => 'GET | POST | PUT | PATCH | DELETE (required)',
                    'path' => 'optional path',
                    'auth_type' => 'none | bearer | api_key | basic | oauth2 (required when no integration_id)',
                    'auth_config' => 'credentials for auth_type (encrypted at rest)',
                ],
                'effect' => 'GET/HEAD/OPTIONS infer read; other methods infer write (gated). Pin with the effect override if needed.',
                'parameters' => 'Inputs are {{placeholder}} tokens in path/body/query — they become the tool call arguments.',
            ],
            'database' => [
                'summary' => 'One parameterized SQL query against an external database. Connected mode borrows the DSN from a database-kind integration; inline mode carries the DSN itself.',
                'config' => [
                    'integration_id' => 'id of a database-kind integration (provides driver/host/credentials) — OR the inline fields below',
                    'driver' => 'pgsql | mysql | sqlite | sqlsrv (required when no integration_id)',
                    'host' => 'optional', 'port' => 'optional integer',
                    'database' => 'required when no integration_id',
                    'username' => 'optional (encrypted)', 'password' => 'optional (encrypted)',
                    'query_template' => 'SQL with :named params, e.g. SELECT * FROM orders WHERE id = :id (required)',
                    'read_only' => 'boolean — true infers a read effect and blocks write keywords',
                ],
                'effect' => 'read_only=true infers read; otherwise write (gated).',
                'parameters' => 'Inputs are the :named params in query_template.',
            ],
            'mcp' => [
                'summary' => 'A reference to an external MCP server; its advertised tools expand into callable tools when the tool is bound to an agent.',
                'config' => [
                    'integration_id' => 'id of an mcp-kind integration when auth_type=oauth2',
                    'endpoint' => 'MCP server URL (required)',
                    'auth_type' => 'none | bearer | api_key | basic | oauth2 (required)',
                    'auth_config' => 'credentials (encrypted)',
                    'mcp_tools' => 'cached [{name, description, input_schema}] from the server tools/list (refreshed by the web app)',
                ],
                'note' => 'MCP tools are NOT run via use_tool/execute_tool — bind the server to an agent so its tools become directly callable, or call the MCP server directly.',
            ],
            'group' => [
                'summary' => 'An ordered collection of other tools. Members live in tool_group_items, set via create_tool/update_tool tool_ids — not in config.',
                'note' => 'Groups are not run through use_tool (it would bypass per-member effect gating); call member tools individually.',
            ],
            'integration' => [
                'summary' => 'A Connection. kind = http (REST/GraphQL), mcp (MCP server) or database. auth_config shape depends on auth_type:',
                'auth_config_by_type' => [
                    'none' => '{} — no credentials',
                    'api_key' => '{ key, value, in: "header"|"query" } (value encrypted)',
                    'bearer' => '{ token } (encrypted)',
                    'basic' => '{ username, password } (encrypted)',
                    'custom_headers' => '{ headers: { Name: value, … } }',
                    'oauth2_client_credentials' => '{ token_url, client_id, client_secret, scope? } — created up front',
                    'oauth2_auth_code' => '{ authorize_url, token_url, client_id, client_secret?, scope?, pkce? } — secret optional for public PKCE clients; needs a per-user browser authorize step done in the web app',
                ],
                'database_kind' => 'For kind=database, base_url is a display DSN and auth_config carries the connection DSN/credentials (encrypted); the http auth_types above do not apply.',
            ],
            'effects_and_gating' => [
                'summary' => 'Every connector action has a read or write effect (inferred from method/operation/read_only, or pinned via the effect override; mcp defaults to write). The safe flag marks a write as pre-approved.',
                'use_tool' => 'Safe default: read effect executes; a non-safe write is REFUSED and returns its blast_radius (never runs). Use this to use any account tool without binding it.',
                'execute_tool' => 'Force-run: executes regardless of effect — a write hits the external system, so confirm with the user first. Withheld from autonomous agents.',
                'binding' => 'A tool bound to an agent (tool_ids) is callable directly inside that agent\'s chats with typed arguments.',
            ],
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('connection_vs_action, rest_api, database, mcp, group, integration, or effects_and_gating. Omit to list topics.'),
        ];
    }
}
