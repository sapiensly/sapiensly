<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Agents\InvokeAgentTool;
use App\Mcp\Tools\Agents\ListAgentsTool;
use App\Mcp\Tools\Build\ProposeChangeTool;
use App\Mcp\Tools\Build\ReadManifestTool;
use App\Mcp\Tools\Build\VerifyWorkflowTool;
use App\Mcp\Tools\Data\CreateRecordTool;
use App\Mcp\Tools\Data\QueryRecordsTool;
use App\Mcp\Tools\Data\SearchKnowledgeTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Sapiensly')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
Sapiensly MCP server. Tools act within your organization's tenant — every app,
record, knowledge base and agent is scoped to you automatically.

- Build/debug apps: read_manifest, then propose_change (RFC 6902 patches, saved
  as reversible versions), and verify_workflow (safe dry-run).
- Tenant data: query_records and search_knowledge (RAG); create_record to write.
- Agents: list_agents, then invoke_agent for a synchronous reply.

Which tools you see depends on your token's abilities.
TXT)]
class SapiensServer extends Server
{
    /**
     * Tools are registered conditionally per request from the token's abilities
     * (see App\Mcp\Tools\SapiensTool::shouldRegister), so a read-only token never
     * sees the write tools.
     */
    protected array $tools = [
        ReadManifestTool::class,
        ProposeChangeTool::class,
        VerifyWorkflowTool::class,
        QueryRecordsTool::class,
        SearchKnowledgeTool::class,
        CreateRecordTool::class,
        ListAgentsTool::class,
        InvokeAgentTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
