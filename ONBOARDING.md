# Onboarding — App Builder

How Sapiensly turns a plain-language description into a working, multi-tenant
internal app: the **manifest** that defines it, the **validator** that guards it,
the **builder** that authors it, and the **runtime** that renders and automates it.

If you're touching anything under `app/Services/Manifest`, `app/Services/Records`,
`app/Services/Workflows`, the builder tools, or the `/r/{app}` runtime — start here.

## The one idea: apps are a manifest

An **App** (`app/Models/App.php`) is a thin row; its real definition is a JSON
**manifest** stored as immutable, numbered **versions** (`AppVersion`). The manifest
declares `objects` (the data model), `pages` (UI blocks), `workflows` (automation),
`permissions` and `settings`. Records live in the tenant `Record` table, scoped by RLS.

Every change creates a new version (reversible). Nothing edits a manifest in place.

- **Authoritative schema:** `resources/schemas/app-manifest/v1.json` — the JSON Schema
  the validator checks against. ⚠️ It lives in `resources/` (ships with every release),
  **not** `storage/`, which is a shared volume that goes stale in prod. Keep it there.
- **Schema as catalog:** `ManifestSchemaCatalog` reads that one file to power the
  "what can I build" tools (field types, blocks, actions, steps, triggers) — it never
  restates the schema, so the schema is the single source of truth.

## Core services (`app/Services/Manifest/`)

| File | Role |
|---|---|
| `AppManifestService` | Lifecycle: read active manifest, `createVersion` (validates first), `initialManifest` (seed for a new app), rollback. |
| `ManifestValidator` | Two-layer validation: JSON-Schema (`validateSchema`) **then** cross-cutting semantic rules JSON Schema can't express (resolved refs, unique slugs, compatible field/block types, relation cardinality, formula cycles + expressions). |
| `ManifestEditor` / `ManifestPatch` | Apply RFC-6902 JSON Patch ops, resolving `/-` appends to concrete indices. |
| `AppScaffolder` | One-shot generate a complete app (objects + belongs-to relations + count rollups + pages/kanban/dashboard) from a description — an LLM call. |

Validation invariants worth knowing (each has a regression test):
- **oneOf errors are pruned by `type`.** A bad step/block/field surfaces only the errors
  for the branch whose `type` matched — not every sibling branch's noise, and no
  spurious root-level `additionalProperties` cascade. (`ManifestValidator::collectSchemaErrors`)
- **Formula fields are validated at author time:** unknown functions, malformed syntax,
  and `{{slug}}` refs to non-existent fields are caught (not just cycles).
- **Workflow context boundary:** workflows only see `{{trigger}}/{{vars}}/{{steps}}/{{current_user}}`.
  `{{form.*}}/{{params.*}}/{{row.*}}` are UI roots and are rejected inside a workflow.

## Two ways an app gets built

1. **Conversational (the product):** `BuilderAiService` drives a `BuilderAgent` over the
   builder tools in `app/Ai/Tools/Builder/`. Entry: `AppBuilderController` + `routes/apps.php`
   (`/apps/{app}/builder/*`), streamed via `Builder*` events. Heavy turns run in
   `RunBuilderAiJob`.
2. **Programmatic (MCP):** `app/Mcp/Tools/Build/` exposes the same surface to external
   agents — `scaffold_app`, `create_app`, `propose_change`, `validate_manifest`,
   `add_object/add_field/add_relation`, the `list_available_*` catalogs,
   `verify_workflow`, versions/rollback. These are the typed, reliable path; prefer
   `add_*` and `validate_manifest` over hand-writing patches.

Both paths converge on `AppManifestService` + `ManifestValidator`, so the rules above
hold no matter who authors.

## Runtime (`/r/{app_slug}` — what end-users see)

- `AppRuntimeController` renders pages; `AppActionController` handles `on_click`/`on_submit`
  actions; `AppFileController` serves uploads. Routes in `routes/apps.php`.
- **Records:** `RecordQueryService` (reads, filters, system fields `sys_created_at`/`sys_updated_at`),
  `RecordWriteService` (writes + validation), `DerivedFieldsResolver` (computes formula/lookup/rollup on read).
- **Expressions:** `ExpressionResolver` resolves `{{…}}` against the runtime/workflow context;
  `SafeExpressionEvaluator` is the sandboxed engine (curated function catalog, no arbitrary PHP).
  ⚠️ A whole-string single token returns a **typed** value; a string mixing literal text
  with tokens is **interpolated** (don't assume mixed strings come back raw).

### Workflow engine (`app/Services/Workflows/WorkflowEngine.php`)

Triggers: `manual`, `record.created/updated/deleted`, `schedule` (cron), `webhook.inbound`.
Steps: `branch` (cases + `default_steps`), `foreach`, `script.run` (QuickJS sandbox, no
network/fs), `set_variable`, `record.create/update/query`, `log`, `ai.complete`,
`agent.invoke`, `http.request` (SSRF-guarded), `connector.call`.
- `verify_workflow` is a side-effect-free dry-run (writes simulated); `run_workflow` is real.
- External/connector writes pause for approval → `WorkflowProposal` (`WorkflowProposalService`).
- Webhook auth: `WorkflowWebhookSignature`.

## Build, test, verify

```bash
composer dev                      # server + queue + vite + logs
composer test                     # full suite (clears config first)
php artisan test tests/Unit/Services/Manifest tests/Feature/Services/Workflows --compact
./vendor/bin/pint --dirty --format agent   # PHP formatting (run before finishing)
```

Tests are the contract — the builder surface is well covered:
- `tests/Unit/Services/Manifest/ManifestValidatorTest.php` — schema + semantic rules (largest).
- `tests/Feature/Services/Records/ExpressionResolverTest.php` — token resolution + interpolation.
- `tests/Feature/Services/Workflows/` — `WorkflowEngineTest`, `AgentInvokeStepTest`, `ConnectorCallStepTest`, approval gate.
- `tests/Feature/Mcp/` — `ValidateManifestPatchTest`, `ManifestEditTest`, `ManifestSchemaToolTest`.
- `tests/Feature/Builder/` — connected-objects read/write, runtime-agent autonomy, integrations.

Tests require **PostgreSQL** (RLS is part of the contract; sqlite won't do).

## Gotchas (learned the hard way)

- **Schema asset must ship with the code** (`resources/`, not `storage/`) or prod serves a
  stale schema while the PHP is current — silently rejects newly-supported features.
- **`scaffold_app` does belongs-to only** — many-to-many, computed fields (formula/lookup/
  rollup), advanced types (slider/file/rich_text/date_range) and workflows need a follow-up
  `add_field`/`propose_change`. It also doesn't infer enums you didn't spell out, and it
  inherits the org's locale/currency (MXN here).
- **`create_record` (MCP) keys are field *slugs*, not ids** (despite the tool blurb).
- **Step/option ids:** `^([a-z]{2,5}_[a-z0-9_]{8,60})$` — ≥8 chars after the prefix. A ULID fits.
- **`migrate:fresh --env=testing` can wipe the dev DB** — verify the target before destructive DB commands.
