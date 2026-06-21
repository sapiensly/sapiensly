<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Orientation + task playbooks for this server: how the abilities map to tools, the conventions to follow, and step-by-step recipes that span tools (e.g. deploy a support squad, build an app, stand up RAG). Call with no topic to get the map and the list of playbooks, then call again with a topic. Per-tool detail lives in each tool description; deep authoring reference is in framework_reference / bot_flow_reference.')]
class GuideTool extends SapiensTool
{
    /** Orientation is useful to every caller regardless of token abilities. */
    protected const ABILITY = null;

    public function handle(Request $request): Response
    {
        $topic = trim((string) $request->get('topic', ''));

        if ($topic === '') {
            return Response::json([
                'what_is_sapiensly' => $this->whatIsSapiensly(),
                'overview' => $this->overview(),
                'abilities' => $this->abilities(),
                'conventions' => $this->conventions(),
                'playbooks' => collect($this->playbooks())
                    ->map(fn (array $p) => ['topic' => $p['topic'], 'when' => $p['when']])
                    ->values()
                    ->all(),
                'next' => 'Call guide again with topic=<one of the playbook topics above, or "abilities" / "conventions"> for the full steps.',
            ]);
        }

        if ($topic === 'abilities') {
            return Response::json(['abilities' => $this->abilities()]);
        }

        if ($topic === 'conventions') {
            return Response::json(['conventions' => $this->conventions()]);
        }

        $playbook = collect($this->playbooks())->firstWhere('topic', $topic);
        if ($playbook === null) {
            return Response::json([
                'error' => "Unknown topic '{$topic}'.",
                'topics' => collect($this->playbooks())->pluck('topic')->push('abilities', 'conventions')->all(),
            ]);
        }

        return Response::json(['playbook' => $playbook]);
    }

    private function whatIsSapiensly(): string
    {
        return 'Sapiensly is a self-serve agentic-AI platform that sells agents which do real work on the write path — they call APIs, process payments, update the CRM, write to databases, and run code in a sandbox. Its modules include Chat, Agents, Apps, Chatbots, Tools, Documents, Knowledge Bases (KBs), and Integrations.';
    }

    private function overview(): string
    {
        return 'This MCP connection lets you build and operate an organization\'s AI workforce end to end — apps, agents, tools, knowledge bases, documents and chatbots — all scoped to your tenant automatically. Call whoami first to see who you act as and the bound org. Then pick a playbook below for a cross-tool recipe; each individual tool\'s description has its own parameter detail.';
    }

    /**
     * @return array<string, string>
     */
    private function abilities(): array
    {
        return [
            '(none)' => 'Identity & orientation: whoami, list_team_members, guide. (get_ai_spend is owner-gated.)',
            'apps:build' => 'Author apps (create_app, read_manifest, propose_change, …), chatbots (create_chatbot/update_chatbot + bot flow tools), tools (create/update/get/delete_tool), agents (list_agent_models, create_agent, update_agent, delete_agent), workflows and integrations.',
            'data:read' => 'Read tenant data: query_records/get_record/aggregate_records, search_knowledge, list/get_knowledge_base, list/get_document.',
            'data:write' => 'Write tenant data: create/update/delete_record, create/update/delete_knowledge_base, add/delete_document.',
            'agents:invoke' => 'Use agents: list_agents, get_agent, invoke_agent (reuse conversation_id for memory), list_conversations.',
            'note' => 'A tool is only visible/callable when your token grants its ability. Authoring an agent is apps:build; invoking one is agents:invoke.',
        ];
    }

    /**
     * @return list<string>
     */
    private function conventions(): array
    {
        return [
            'Drafts first: create_* makes a draft (app/agent/tool/chatbot). Configure it, then the matching update_* with status=active publishes it.',
            'Partial updates: every update_* changes only the fields you pass — omit the rest to leave them untouched.',
            'Apps are patched, not replaced: read_manifest, then propose_change with RFC 6902 ops. Each change is validated and saved as a reversible version; rollback_app reverts. validate_manifest dry-checks a draft first.',
            'Secrets are protected: tool auth/credentials are encrypted at rest and masked in get_tool — re-send a secret only when changing it.',
            'Destructive tools are irreversible: delete_* and a full delete_document remove data for good — confirm with the user first.',
            'Everything is tenant-scoped: you only ever see and write your own organization\'s data; ids from other tenants are simply not visible.',
        ];
    }

    /**
     * @return list<array{topic: string, when: string, steps: list<string>}>
     */
    private function playbooks(): array
    {
        return [
            [
                'topic' => 'support_squad',
                'when' => 'Deploy an autonomous customer-service squad (the MVP): knowledge + action + triage behind a chatbot.',
                'steps' => [
                    '1. create_knowledge_base — a corpus for your manuals/FAQs.',
                    '2. add_document (knowledge_base_id=…) — feed it raw text; repeat per doc. Embedding runs async; check get_knowledge_base until documents are embedding_status=ready, then sanity-check with search_knowledge.',
                    '3. list_agent_models — pick a model id for your agents.',
                    '4. create_agent type=knowledge — attach the KB via knowledge_base_ids so it answers from RAG.',
                    '5. create_tool (rest_api/function/…) for real actions (look up an order, issue a refund); test_tool_connection; then create_agent type=action with those tool_ids.',
                    '6. (optional) create_agent type=triage to classify intent/urgency/sentiment first.',
                    '7. create_chatbot — a draft widget bot.',
                    '8. scaffold_bot_flow (describe the conversation, using your agents) → review → update_bot_flow to persist → test_bot_flow to step through it.',
                    '9. update_chatbot status=active to publish; set allowed_origins for the sites that embed the widget.',
                ],
            ],
            [
                'topic' => 'build_app',
                'when' => 'Build a data app (objects, pages, workflows).',
                'steps' => [
                    '1. create_app (slug matches ^[a-z][a-z0-9_]*$, unique in your account).',
                    '2. Browse the catalogs before authoring: list_available_components / list_available_field_types / list_available_actions / list_available_triggers / list_available_steps; framework_reference for depth.',
                    '3. read_manifest, then propose_change with RFC 6902 ops to add objects/pages/workflows. validate_manifest dry-checks a draft manifest first.',
                    '4. list_app_versions / rollback_app to manage history (every change is a reversible version).',
                    '5. Workflows: verify_workflow (dry-run, writes simulated) then run_workflow; approve_workflow_proposal for gated writes.',
                    '6. Records (data:read/write): query_records / create_record / update_record once objects exist.',
                ],
            ],
            [
                'topic' => 'rag',
                'when' => 'Stand up retrieval-augmented knowledge an agent can search.',
                'steps' => [
                    '1. create_knowledge_base (chunk_size / chunk_overlap optional).',
                    '2. add_document (knowledge_base_id=…) from raw text. Binary files (PDF/DOCX) are uploaded via the web app, not MCP.',
                    '3. get_knowledge_base to watch documents reach embedding_status=ready.',
                    '4. search_knowledge to test retrieval.',
                    '5. Attach the KB to an agent: create_agent or update_agent with knowledge_base_ids.',
                ],
            ],
            [
                'topic' => 'chatbot',
                'when' => 'Author a widget chatbot and its conversation flow.',
                'steps' => [
                    '1. create_chatbot — a draft widget bot with a blank flow (single start node).',
                    '2. bot_flow_reference — read the node/edge schema before authoring.',
                    '3. scaffold_bot_flow (plain-language description, using your agents) → update_bot_flow (persist) → test_bot_flow (step with messages).',
                    '4. update_chatbot status=active and set allowed_origins to publish the widget.',
                ],
            ],
            [
                'topic' => 'agent',
                'when' => 'Create, configure and run an agent.',
                'steps' => [
                    '1. list_agent_models — pick a valid model id.',
                    '2. create_agent (type, model, prompt); attach capabilities via tool_ids (list_tools) and knowledge_base_ids (list_knowledge_bases).',
                    '3. update_agent status=active to publish; switch model or edit the prompt anytime (partial update).',
                    '4. invoke_agent to talk to it — reuse the returned conversation_id for memory; list_conversations to resume an earlier thread.',
                ],
            ],
            [
                'topic' => 'tool',
                'when' => 'Add a connector operation (one external action) an agent can call.',
                'steps' => [
                    '1. create_tool with a type (function / rest_api / graphql / database / mcp / group) and its type-specific config; secrets are encrypted at rest.',
                    '2. get_tool to review the masked config + resolved typed contract (inputs/outputs/effect); test_tool_connection to check reachability.',
                    '3. update_tool status=active; execute_tool to run it — a write tool performs a real external operation, so confirm first.',
                    '4. Attach it to an agent via create_agent / update_agent tool_ids.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('A playbook topic (support_squad, build_app, rag, chatbot, agent, tool) or "abilities" / "conventions". Omit to get the map and the list of playbooks.'),
        ];
    }
}
