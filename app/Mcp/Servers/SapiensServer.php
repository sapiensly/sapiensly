<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Sapiensly')]
#[Version('0.2.0')]
#[Instructions(<<<'TXT'
Sapiensly MCP server. Tools act within your organization's tenant — every app,
record, knowledge base, chatbot and agent is scoped to you automatically. Which
tools you see depends on your token's abilities.

Start with whoami to see who you're acting as (user + role) and the organization
this connection is bound to.

Build & debug apps (apps:build):
  - list_apps, read_manifest, then propose_change (RFC 6902 patches, saved as
    reversible versions); validate_manifest checks a draft first.
  - Catalogs (call before authoring): list_available_components,
    list_available_field_types, list_available_actions, list_available_triggers,
    list_available_steps, and framework_reference for deeper guidance.
  - Versions: list_app_versions, rollback_app.
  - Workflows: verify_workflow (safe dry-run), run_workflow (real), and
    list_workflow_runs / get_workflow_run to debug; gated writes surface via
    list_workflow_proposals → approve_workflow_proposal / dismiss_workflow_proposal.

Chatbots (apps:build):
  - list_chatbots, read_bot_flow, scaffold_bot_flow (generate from a prompt),
    update_bot_flow (persist a definition), test_bot_flow (step it with messages).

Integrations (apps:build):
  - list_integrations, list_tools, list_connector_actions, test_tool_connection,
    execute_tool (a write tool performs a real external operation — confirm first).

Tenant data (data:read / data:write):
  - query_records, get_record, aggregate_records, search_knowledge (RAG),
    list_knowledge_bases, list_documents; create_record / update_record /
    delete_record to write.

Agents (agents:invoke):
  - list_agents, get_agent, invoke_agent (synchronous reply).
TXT)]
class SapiensServer extends Server
{
    /**
     * Tools are registered conditionally per request from the token's abilities
     * (see App\Mcp\Tools\SapiensTool::shouldRegister), so e.g. a read-only token
     * never sees the write tools.
     */
    protected array $tools = [
        // Identity & context.
        Tools\Account\WhoamiTool::class,
        Tools\Account\ListTeamMembersTool::class,
        Tools\Account\GetAiSpendTool::class,
        // Build & debug apps.
        Tools\Build\ListAppsTool::class,
        Tools\Build\ReadManifestTool::class,
        Tools\Build\ProposeChangeTool::class,
        Tools\Build\ValidateManifestTool::class,
        Tools\Build\ListAppVersionsTool::class,
        Tools\Build\RollbackAppTool::class,
        Tools\Build\VerifyWorkflowTool::class,
        // Build catalogs.
        Tools\Build\ListAvailableComponentsTool::class,
        Tools\Build\ListAvailableFieldTypesTool::class,
        Tools\Build\ListAvailableActionsTool::class,
        Tools\Build\ListAvailableTriggersTool::class,
        Tools\Build\ListAvailableStepsTool::class,
        Tools\Build\FrameworkReferenceTool::class,
        // Workflows.
        Tools\Workflows\RunWorkflowTool::class,
        Tools\Workflows\ListWorkflowRunsTool::class,
        Tools\Workflows\GetWorkflowRunTool::class,
        Tools\Workflows\ListWorkflowProposalsTool::class,
        Tools\Workflows\ApproveWorkflowProposalTool::class,
        Tools\Workflows\DismissWorkflowProposalTool::class,
        // Chatbots & bot flows.
        Tools\Chatbots\ListChatbotsTool::class,
        Tools\Chatbots\ReadBotFlowTool::class,
        Tools\Chatbots\ScaffoldBotFlowTool::class,
        Tools\Chatbots\UpdateBotFlowTool::class,
        Tools\Chatbots\TestBotFlowTool::class,
        // Integrations & tools.
        Tools\Integrations\ListIntegrationsTool::class,
        Tools\Integrations\ListToolsTool::class,
        Tools\Integrations\ListConnectorActionsTool::class,
        Tools\Integrations\TestToolConnectionTool::class,
        Tools\Integrations\ExecuteToolTool::class,
        // Tenant data.
        Tools\Data\QueryRecordsTool::class,
        Tools\Data\GetRecordTool::class,
        Tools\Data\AggregateRecordsTool::class,
        Tools\Data\CreateRecordTool::class,
        Tools\Data\UpdateRecordTool::class,
        Tools\Data\DeleteRecordTool::class,
        Tools\Data\SearchKnowledgeTool::class,
        Tools\Data\ListKnowledgeBasesTool::class,
        Tools\Data\ListDocumentsTool::class,
        // Agents.
        Tools\Agents\ListAgentsTool::class,
        Tools\Agents\GetAgentTool::class,
        Tools\Agents\InvokeAgentTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];

    /**
     * The Sapiensly mark, advertised in the MCP serverInfo so clients (e.g.
     * claude.ai) display it next to the connector. Absolute URLs so a remote
     * client can fetch them.
     *
     * @return list<Icon>
     */
    protected function icons(): array
    {
        return [
            Icon::from(url('favicon.svg'), 'image/svg+xml'),
            Icon::from(url('favicon/android-chrome-512x512.png'), 'image/png', ['512x512']),
        ];
    }
}
