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
| `apps:build` | Build/debug apps & chatbots, workflows, integrations, and **delete** apps/agents/chatbots. |
| `data:read` | Read records & knowledge. |
| `data:write` | Create/update/delete records. |
| `agents:invoke` | List/inspect/invoke agents. |

Leaving abilities empty grants all of them.

## Tool catalog (47 tools)

### Identity & context

| Tool | Ability | Description |
|---|---|---|
| `whoami` | — | Who you act as (user + role) and the bound organization (name, members, AI limits). Call this first. |
| `list_team_members` | — | The org's members with name, email, role and status. |
| `get_ai_spend` | owner | The org's AI spend over 7/30/90 days: cost, calls, tokens, own-vs-system split, per-model breakdown, daily series, and budgets/caps. |

### Build & debug apps (`apps:build`)

| Tool | Description |
|---|---|
| `list_apps` | List the apps you can build/edit. |
| `read_manifest` | Read an app's active manifest (objects, pages, workflows, agent config). |
| `propose_change` | Apply RFC 6902 JSON Patch ops to the manifest → saved as a reversible version. |
| `validate_manifest` | Validate a draft manifest WITHOUT applying it. |
| `list_app_versions` | The app's version history, newest first. |
| `rollback_app` | Roll the app back to a previous version (append-only). |
| `delete_app` | **Permanently delete** an app and its versions/records. |

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
| `search_knowledge` | data:read | RAG search across the tenant's knowledge bases. |
| `list_knowledge_bases` | data:read | Knowledge bases with status and counts. |
| `list_documents` | data:read | Documents in the account, optionally per knowledge base. |

### Agents (`agents:invoke`)

| Tool | Ability | Description |
|---|---|---|
| `list_agents` | agents:invoke | Agents you can use (id, name, type, status). |
| `get_agent` | agents:invoke | Full agent config: model, system prompt, tools, knowledge bases. |
| `invoke_agent` | agents:invoke | Send a message to an agent and get its reply (synchronous). |
| `delete_agent` | apps:build | **Permanently delete** an agent. |

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
expanded catalog). Shared helpers (`mcpOrg`/`mcpMember`/`mcpToken`) live in
`tests/Pest.php`.
