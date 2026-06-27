# Sapiensly MCP Server

An [MCP](https://modelcontextprotocol.io) server (`laravel/mcp`) that lets external
AI clients — **Claude Code** and **Claude web (claude.ai)** — build apps and
chatbots, read and write tenant data, and talk to agents inside a Sapiensly
organization.

- **Server:** `app/Mcp/Servers/SapiensServer.php`
- **Tools:** `app/Mcp/Tools/**` (each extends `App\Mcp\Tools\SapiensTool`)
- **Route:** `routes/ai.php` → `mcp/{organization}/v1`

## Architecture

- **Organization-bound, per-org URL.** The endpoint is `mcp/{organization}/v1`
  (the org **slug**). The org comes from the URL — not the user's mutable active
  org — so a connection is pinned to one organization. A user who belongs to
  several orgs uses a different URL per org.
- **Auth (two credentials, same URL):**
  1. a personal **`McpAccessToken`** bearer (Claude Code), which must be issued
     for that org;
  2. an **OAuth 2.1** access token from Passport (claude.ai's connector flow).
  Either way the principal must be an **active member** of the URL's org, and the
  org is pinned in-memory so `HasVisibility::forAccountContext` + RLS agree.
  Middleware: `AuthenticateMcpToken` → `throttle:mcp` → `BindMcpTenantContext`.
- **Tenant isolation.** Every tool runs as the token's user within the bound org;
  data is scoped by Postgres RLS + `forAccountContext`. A token for org A cannot
  see org B.
- **Abilities.** A token carries abilities; a tool only registers (is visible +
  callable) when the token grants its ability — a read-only token never sees the
  write/delete tools. See the table below.
- **No pagination.** `tools/list` returns the whole catalog in one page.
- **Management UI.** Tokens are created/revoked by **owners** under
  **System → MCP Access** (`/system/mcp`), or via `php artisan mcp:token`.

## Connecting

**Claude Code** (personal token):

```bash
php artisan mcp:token <user> --org=<org-slug> --abilities=apps:build,data:read,data:write,agents:invoke
claude mcp add --transport http sapiensly https://<app>/mcp/<org-slug>/v1 \
  --header "Authorization: Bearer <token>"
```

**Claude web:** in claude.ai → Settings → Connectors → Add custom connector →
paste `https://<app>/mcp/<org-slug>/v1` and authorize (OAuth, no token).

## Abilities

| Ability | Grants |
|---|---|
| _(none)_ | Identity tools (`whoami`, `list_team_members`); `get_ai_spend` is owner-gated. |
| `apps:build` | Create/build/debug & delete apps and chatbots; workflows; manage tools and **author** agents (create/update/delete); integrations. |
| `data:read` | Read records, knowledge bases and documents. |
| `data:write` | Create/update/delete records, knowledge bases and documents. |
| `agents:invoke` | List/inspect/**invoke** agents and resume conversations. |

Leaving abilities empty grants all of them. Note the split for agents:
**authoring** one (create/update/delete) is `apps:build`, while **invoking** it
and listing conversations is `agents:invoke`.

## Tool catalog (68 tools)

### Identity & context

| Tool | Ability | Description |
|---|---|---|
| `whoami` | — | Who you act as (user + role) and the bound organization (name, members, AI limits). Call this first. |
| `guide` | — | Orientation + cross-tool task playbooks (deploy a support squad, build an app, RAG, …), the abilities map and conventions. Call with a `topic` for full steps. |
| `list_team_members` | — | The org's members with name, email, role and status. |
| `get_ai_spend` | owner | The org's AI spend over 7/30/90 days: cost, calls, tokens, own-vs-system split, per-model breakdown, daily series, and budgets/caps. |
| `get_organization_brand` | — | The org Brandbook (logo, icon, colours, font, theme) that apps & chatbots inherit — read it to build on-brand. |
| `set_organization_brand` | owner | Set the org Brandbook (partial; pass null to clear a field). Owner/sysadmin only. |

### Build & debug apps (`apps:build`)

| Tool | Description |
|---|---|
| `create_app` | Create a new app, seeded with an empty valid manifest as version 1. |
| `list_apps` | List the apps you can build/edit. |
| `read_manifest` | Read an app's active manifest (objects, pages, workflows, agent config). |
| `propose_change` | Apply RFC 6902 JSON Patch ops to the manifest → saved as a reversible version. |
| `validate_manifest` | Validate a draft manifest WITHOUT applying it. |
| `list_app_versions` | The app's version history, newest first. |
| `rollback_app` | Roll the app back to a previous version (append-only). |
| `delete_app` | **Permanently delete** an app and its versions/records. |
| `list_app_roles` | The app's access_mode + manifest roles + each org member's current app role. Admin-gated. |
| `assign_app_role` | Grant/replace an org member's app role (member by email; role must be a manifest role). Admin-gated. |
| `revoke_app_role` | Remove a member's explicit role, dropping them to the default (open) or no access (allowlist). Admin-gated. |

### Build catalogs (`apps:build`)

| Tool | Description |
|---|---|
| `list_available_components` | UI block types you may put in a page. |
| `list_available_field_types` | Field types you may use on an object. |
| `list_available_actions` | Action types for on_click / on_submit. |
| `list_available_triggers` | Workflow trigger types + context tokens. |
| `list_available_steps` | Workflow step types, props and outputs. |
| `framework_reference` | Deep authoring reference; call with no topic to list topics. |

### Workflows (`apps:build`)

| Tool | Description |
|---|---|
| `verify_workflow` | Dry-run a workflow (writes simulated) and return the per-step trace. |
| `run_workflow` | Run a workflow for real; gated writes pause for approval. |
| `list_workflow_runs` | An app's recent runs with status. |
| `get_workflow_run` | Full per-step trace of a run by id. |
| `list_workflow_proposals` | Pending gated write-approvals for an app. |
| `approve_workflow_proposal` | Approve a pending proposal (performs the real write). |
| `dismiss_workflow_proposal` | Reject a pending proposal. |

### Chatbots & bot flows (`apps:build`)

| Tool | Description |
|---|---|
| `list_chatbots` | Your chatbots and whether they have a bot flow. |
| `get_chatbot` | A single chatbot's full config (status, channel, widget config, allowed origins, agent roster). |
| `create_chatbot` | Create a draft widget chatbot (companion channel + blank bot flow). |
| `update_chatbot` | Partial update of the chatbot's own config (name, status, visibility, widget config, allowed origins); `status=active` publishes it. |
| `bot_flow_reference` | Authoring reference for bot flows: node types, fields, edges and a worked example. Read before editing a flow; pass `node_type` to drill in. |
| `read_bot_flow` | The chatbot's flow graph (nodes/edges). |
| `scaffold_bot_flow` | Generate a flow from a plain-language description (returns, doesn't save). |
| `update_bot_flow` | Replace a chatbot's flow definition (validated). |
| `test_bot_flow` | Step a flow with a message; pass `state` back to continue. |
| `delete_chatbot` | **Permanently delete** a chatbot and its bot flow. |

### Integrations & tools (`apps:build`)

| Tool | Description |
|---|---|
| `list_integrations` | Provisioned integrations (REST/GraphQL/database/MCP). |
| `list_tools` | Connector operations, with effect (read/write) and safe flag. |
| `get_tool` | A tool's full config (secrets masked) + resolved connector contract (typed IO + effect). |
| `create_tool` | Create a tool of a given type (function/mcp/rest_api/graphql/database/group); secrets encrypted at rest. |
| `update_tool` | Partial update of a tool (type immutable; secrets merged + encrypted). |
| `delete_tool` | **Delete** a tool. |
| `list_connector_actions` | Typed connector actions (inputs/outputs/effect). |
| `test_tool_connection` | Check a tool/connector can reach its endpoint. |
| `execute_tool` | Run a tool with parameters (a write tool performs a real external op). |

### Tenant data (`data:read` / `data:write`)

| Tool | Ability | Description |
|---|---|---|
| `query_records` | data:read | Query records of an object (filter/sort/limit). |
| `get_record` | data:read | Fetch a single record by id. |
| `aggregate_records` | data:read | count / sum / avg / min / max over an object. |
| `create_record` | data:write | Create a record (validated against the object's fields). |
| `update_record` | data:write | Update fields on a record. |
| `delete_record` | data:write | **Delete** a record by id. |

### Knowledge bases (`data:read` / `data:write`)

| Tool | Ability | Description |
|---|---|---|
| `search_knowledge` | data:read | RAG search across the tenant's knowledge bases. |
| `list_knowledge_bases` | data:read | Knowledge bases with status and counts. |
| `get_knowledge_base` | data:read | A KB's config (chunking), status, counts and attached documents. |
| `create_knowledge_base` | data:write | Create a RAG corpus (configurable chunking). |
| `update_knowledge_base` | data:write | Partial update (name, description, keywords, chunking config). |
| `delete_knowledge_base` | data:write | **Delete** a knowledge base. |

### Documents (`data:read` / `data:write`)

| Tool | Ability | Description |
|---|---|---|
| `list_documents` | data:read | Documents in the account, optionally per knowledge base. |
| `get_document` | data:read | A document's body (if inline) and the KBs it's attached to. |
| `add_document` | data:write | Add a document from raw text; optionally attach to a KB (triggers embedding). |
| `delete_document` | data:write | **Delete** a document, or just detach it from one KB. |

### Agents (`agents:invoke` to use, `apps:build` to author)

| Tool | Ability | Description |
|---|---|---|
| `list_agents` | agents:invoke | Agents you can use (id, name, type, status). |
| `get_agent` | agents:invoke | Full agent config: model, system prompt, tools, knowledge bases. |
| `invoke_agent` | agents:invoke | Send a message to an agent and get its reply (synchronous); reuse `conversation_id` for memory. |
| `list_conversations` | agents:invoke | Your existing agent conversations, to resume one. |
| `list_agent_models` | apps:build | The chat model ids you can assign to an agent (the picker catalog). |
| `create_agent` | apps:build | Create a draft agent (type, model, prompt, tools, knowledge bases). |
| `update_agent` | apps:build | Partial update of an agent (e.g. `status=active` to publish, or switch model). |
| `delete_agent` | apps:build | **Permanently delete** an agent. |

## Internal agents (platform tools)

The same catalogue is also exposed **in-process** to every Sapiensly agent/model
so an internal agent can build and orchestrate like an external client — without
authoring `Tool` records. This is a one-way bridge from the MCP tools to the
Laravel AI SDK; the MCP server itself is untouched.

- **Bridge:** `app/Ai/Tools/Platform/McpBridgeTool.php` adapts one `SapiensTool`
  to a `Laravel\Ai\Contracts\Tool` (`description()`/`schema()` delegate). Its
  `handle()` runs the MCP handler **as the agent's owner** — it sets the auth
  user + `TenantContext` (RLS GUCs) to the owner and restores them after — so the
  handler's own `forAccountContext` + `$user->can()` + RLS cap every call at the
  owner. **An agent can never do more than the user who owns it.**
- **Factory:** `app/Ai/Tools/Platform/PlatformToolsFactory.php` bridges
  `SapiensServer::TOOLS` (the same `public const` the server registers — no
  drift) **minus a `DENYLIST`** of destructive/irreversible ops, the
  "read + safe writes" posture: `delete_*`, `execute_tool`, `run_workflow`,
  `approve_/dismiss_workflow_proposal`, `rollback_app`, `test_tool_connection`.
  `merge()` dedupes so an explicitly-attached tool wins.
- **Where it's injected (every agent-run seam):** `LLMService::buildAgent`
  (covers `invoke_agent` + the `chatWith*` paths; routing/triage runs opt out),
  `ChatAiService::buildChatTools`, and `RunDebateTurnJob`. Each bridged tool gets
  a unique class name via `app/Ai/Tools/RuntimeToolFactory.php`.
- **Excluded by design:** the **app-runtime agents** (`RuntimeAgentService` /
  `RuntimeAgentToolset`) — they are a deliberate sandbox scoped to what the app
  manifest grants, so the platform catalogue is *not* added there. The
  **builder** meta-agent is likewise excluded.
- **Recursion:** `invoke_agent` stays in the safe set with a depth cap (3) on top
  of the `AiSpendGuard` budget ceiling.
- **A new MCP tool propagates automatically** — a Pest drift guard
  (`tests/Feature/Ai/PlatformToolsTest.php`) fails CI if a registered tool is
  neither exposed nor denylisted, forcing an explicit safe/unsafe call.

## Conventions

- **propose-don't-apply** — app/agent writes go through validated, reversible
  versions or approval-gated proposals; destructive tools (`delete_*`) warn and
  are irreversible.
- **Tool names** are clean snake_case derived from the class name
  (`ReadManifestTool` → `read_manifest`) via `SapiensTool::name()`.
- **Server icon** — the Sapiensly mark is advertised in `serverInfo.icons`
  (claude.ai does not render connector icons yet — known client-side gap).

## Tests

`tests/Feature/Mcp/**` (auth/binding, tool handlers, ability gating, the
expanded catalog) — including the management slices
`{Tool,KnowledgeBase,Document,App,Chatbot}ManagementTest` and agent
create/update/invoke in `SapiensServerTest`. Shared helpers
(`mcpOrg`/`mcpMember`/`mcpToken`) live in `tests/Pest.php`.
