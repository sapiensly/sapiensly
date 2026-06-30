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
this connection is bound to. Call guide for task playbooks that span tools
(deploy a support squad, build an app, stand up RAG, …) and the abilities map.

Build & debug apps (apps:build):
  - create_app starts a new app (empty valid manifest, version 1). Then
    list_apps, read_manifest, and propose_change (RFC 6902 patches, saved as
    reversible versions); validate_manifest checks a draft first.
  - Catalogs (call before authoring): list_available_components,
    list_available_field_types, list_available_actions, list_available_triggers,
    list_available_steps, list_available_icons, and framework_reference for deeper
    guidance (topics include design, palette, icons, custom_css, permissions).
  - Theming & polish: get_organization_brand reads the org Brandbook (logo/colours/
    font); new apps inherit it automatically, so build on-brand. set_organization_brand
    changes it org-wide (owner/sysadmin). generate_palette derives a professional
    colour palette (also live as CSS vars on every app); use named icons
    (list_available_icons) on buttons/stats/cards and custom_css for fine touches —
    keep it executive, not loud.
  - Versions: list_app_versions, rollback_app.
  - Builder sessions (the in-app AI app-builder chats): list_builder_conversations
    (by app_slug) and get_builder_conversation (full transcript: each turn's
    summary, proposal status, applied version) to debug or see where a build left
    off; continue_builder_conversation resumes it — posts a message and runs a
    real builder turn (auto-applies the proposal as a new version by default).
  - Access (who can use the app and in which role): list_app_roles, then
    assign_app_role / revoke_app_role (member by email). The manifest DEFINES the
    roles + policies (see framework_reference topic=permissions); these tools
    assign people to them at runtime. Admin-gated (app/org owner).
  - Workflows: verify_workflow (safe dry-run), run_workflow (real), and
    list_workflow_runs / get_workflow_run to debug; gated writes surface via
    list_workflow_proposals → approve_workflow_proposal / dismiss_workflow_proposal.

Chatbots (apps:build):
  - Manage the chatbot: list_chatbots, get_chatbot (full config + roster),
    create_chatbot (draft widget bot with a blank flow), update_chatbot (partial
    — name/status/visibility/widget config; set status=active to publish),
    delete_chatbot.
  - Author its conversation: bot_flow_reference (the node/edge schema — read
    before authoring a flow), read_bot_flow, scaffold_bot_flow (generate from a
    prompt), update_bot_flow (persist a definition), test_bot_flow (step it).

Integrations & tools (apps:build) — "Connection vs Action": an Integration is the
connection (base URL + auth shared by tools); a Tool is one operation that may
reference a connection via config.integration_id. Read tools_reference for the
config/auth_config shapes.
  - Manage connections: list_integrations, get_integration (auth masked),
    create_integration, update_integration, delete_integration,
    test_integration_connection.
  - Manage tools (one tool = one connector operation): list_tools, get_tool
    (config masked + typed contract), create_tool (type mcp/rest_api/database/
    group + its config; secrets encrypted at rest), update_tool, delete_tool.
  - Inspect + run: list_connector_actions (typed inputs/outputs/effect),
    test_tool_connection, use_tool (safe-by-default: runs read tools, refuses
    unmarked writes) and execute_tool (force-run — a write performs a real
    external operation, confirm with the user first).

Tenant data (data:read / data:write):
  - Records: query_records, get_record, aggregate_records; create_record /
    update_record / delete_record to write.
  - Knowledge bases (RAG): search_knowledge, list_knowledge_bases,
    get_knowledge_base; create_knowledge_base / update_knowledge_base /
    delete_knowledge_base to manage the corpus.
  - Documents: list_documents, get_document; add_document feeds raw text into a
    KB (triggers embedding); delete_document removes it (or just detaches from
    one KB). Binary files (PDF/DOCX) are uploaded via the web app.

Agents (agents:invoke):
  - list_agents, get_agent, invoke_agent (synchronous reply). For a multi-turn
    conversation, reuse the conversation_id invoke_agent returns on the next
    call; list_conversations resumes an earlier thread.
TXT)]
class SapiensServer extends Server
{
    /**
     * Return the whole catalog in a single tools/list page (no pagination /
     * nextCursor). Both must clear the tool count — perPage is min(default, max).
     */
    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

    /**
     * Tools are registered conditionally per request from the token's abilities
     * (see App\Mcp\Tools\SapiensTool::shouldRegister), so e.g. a read-only token
     * never sees the write tools.
     *
     * Declared as a public const so the same catalog can be reused internally
     * (App\Ai\Tools\Platform\PlatformToolsFactory bridges it to in-process
     * agents) without drifting from what external clients see.
     */
    public const TOOLS = [
        // Identity & context.
        Tools\Account\WhoamiTool::class,
        Tools\Account\GuideTool::class,
        Tools\Account\ListTeamMembersTool::class,
        Tools\Account\GetAiSpendTool::class,
        Tools\Account\GetOrganizationBrandTool::class,
        Tools\Account\SetOrganizationBrandTool::class,
        // Build & debug apps.
        Tools\Build\ListAppsTool::class,
        Tools\Build\CreateAppTool::class,
        Tools\Build\ScaffoldAppTool::class,
        Tools\Build\ReadManifestTool::class,
        Tools\Build\GetManifestSchemaTool::class,
        // Builder chat sessions (debug / resume the in-app AI app-builder).
        Tools\Build\ListBuilderConversationsTool::class,
        Tools\Build\GetBuilderConversationTool::class,
        Tools\Build\ContinueBuilderConversationTool::class,
        Tools\Build\AddObjectTool::class,
        Tools\Build\AddFieldTool::class,
        Tools\Build\AddRelationTool::class,
        Tools\Build\ProposeChangeTool::class,
        Tools\Build\ValidateManifestTool::class,
        Tools\Build\ListAppVersionsTool::class,
        Tools\Build\RollbackAppTool::class,
        Tools\Build\DeleteAppTool::class,
        Tools\Build\VerifyWorkflowTool::class,
        // App access (who can use an app and in which role).
        Tools\Build\ListAppRolesTool::class,
        Tools\Build\AssignAppRoleTool::class,
        Tools\Build\RevokeAppRoleTool::class,
        // Build catalogs.
        Tools\Build\ListAvailableComponentsTool::class,
        Tools\Build\ListAvailableIconsTool::class,
        Tools\Build\GeneratePaletteTool::class,
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
        Tools\Chatbots\GetChatbotTool::class,
        Tools\Chatbots\CreateChatbotTool::class,
        Tools\Chatbots\UpdateChatbotTool::class,
        Tools\Chatbots\BotFlowReferenceTool::class,
        Tools\Chatbots\ReadBotFlowTool::class,
        Tools\Chatbots\ScaffoldBotFlowTool::class,
        Tools\Chatbots\UpdateBotFlowTool::class,
        Tools\Chatbots\TestBotFlowTool::class,
        Tools\Chatbots\DeleteChatbotTool::class,
        // Integrations & tools.
        Tools\Integrations\ListIntegrationsTool::class,
        Tools\Integrations\GetIntegrationTool::class,
        Tools\Integrations\CreateIntegrationTool::class,
        Tools\Integrations\UpdateIntegrationTool::class,
        Tools\Integrations\DeleteIntegrationTool::class,
        Tools\Integrations\TestIntegrationConnectionTool::class,
        Tools\Integrations\ListToolsTool::class,
        Tools\Integrations\GetToolTool::class,
        Tools\Integrations\CreateToolTool::class,
        Tools\Integrations\UpdateToolTool::class,
        Tools\Integrations\DeleteToolTool::class,
        Tools\Integrations\ListConnectorActionsTool::class,
        Tools\Integrations\TestToolConnectionTool::class,
        Tools\Integrations\UseToolTool::class,
        Tools\Integrations\ExecuteToolTool::class,
        Tools\Integrations\ToolsReferenceTool::class,
        // Tenant data.
        Tools\Data\DescribeAppDataTool::class,
        Tools\Data\QueryRecordsTool::class,
        Tools\Data\GetRecordTool::class,
        Tools\Data\AggregateRecordsTool::class,
        Tools\Data\CreateRecordTool::class,
        Tools\Data\UpdateRecordTool::class,
        Tools\Data\DeleteRecordTool::class,
        Tools\Data\GenerateDemoDataTool::class,
        Tools\Data\SearchKnowledgeTool::class,
        Tools\Data\EstimateIngestionCostTool::class,
        Tools\Data\EstimateRetrievalCostTool::class,
        // Knowledge bases.
        Tools\Data\ListKnowledgeBasesTool::class,
        Tools\Data\GetKnowledgeBaseTool::class,
        Tools\Data\CreateKnowledgeBaseTool::class,
        Tools\Data\UpdateKnowledgeBaseTool::class,
        Tools\Data\DeleteKnowledgeBaseTool::class,
        // Documents.
        Tools\Data\ListDocumentsTool::class,
        Tools\Data\GetDocumentTool::class,
        Tools\Data\AddDocumentTool::class,
        Tools\Data\DeleteDocumentTool::class,
        // Agents.
        Tools\Agents\ListAgentsTool::class,
        Tools\Agents\GetAgentTool::class,
        Tools\Agents\ListAgentModelsTool::class,
        Tools\Agents\CreateAgentTool::class,
        Tools\Agents\UpdateAgentTool::class,
        Tools\Agents\InvokeAgentTool::class,
        Tools\Agents\ListConversationsTool::class,
        Tools\Agents\DeleteAgentTool::class,
        // Chat history (search / retrieve / continue conversations).
        Tools\Chats\ListChatsTool::class,
        Tools\Chats\GetChatTool::class,
        Tools\Chats\SearchChatMessagesTool::class,
        Tools\Chats\ContinueChatTool::class,
    ];

    /** @var list<class-string> */
    protected array $tools = self::TOOLS;

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
        $icons = [];

        // Inline the mark as a data URI so the client never has to fetch it
        // (no CORS / redirect / 404 failure mode). The MCP spec allows data URIs.
        $svg = @file_get_contents(public_path('favicon.svg'));
        if ($svg !== false) {
            $icons[] = Icon::from('data:image/svg+xml;base64,'.base64_encode($svg), 'image/svg+xml');
        }

        // Raster fallback (absolute URL) for clients that don't render SVG.
        $icons[] = Icon::from(url('favicon/android-chrome-512x512.png'), 'image/png', ['512x512']);

        return $icons;
    }
}
