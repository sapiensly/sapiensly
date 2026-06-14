# App Builder — Conversational Integrations & Connected Objects (Design)

> **Status:** Design (pre-implementation, forward-looking). Extends
> [the App Builder vision](./sapiensly-app-builder-vision.md). Scope chosen:
> the **full** connected-objects path, with **OAuth2 (discovery) + api-key/bearer**
> integrations. Design first — no code until this is agreed.

---

## 0. What we're building

Two new powers for the App Builder, both delivered **in the conversation**:

1. **Create & verify integrations conversationally** — the builder can stand up a
   working connection to an external system (OAuth2-discoverable *or*
   key/bearer), authorize it, and prove it works, without anyone touching a form.
2. **Connected objects** — a manifest object whose **backing store is that
   external system** instead of our internal records table. A page that lists or
   edits such an object reads/writes **live** against the customer's system of
   record.

Together: a built app stops being another CRUD island and becomes a **live
control surface over the system the business already runs on.** This is the
"connected objects = the unlock" thesis made concrete.

We build on primitives that already exist — no OAuth from scratch:
`OAuth2DiscoveryService` (discovers + dynamically registers a client from a URL),
`IntegrationService::create`, `IntegrationRequestExecutor` (authed, SSRF-guarded,
logged HTTP), and the builder's `propose → approve` loop with `AppVersion` snapshots.

---

## 1. Current boundary (from exploration)

- The App Builder is a **manifest-only editor**: its LLM proposes RFC-6902 patches
  to objects / pages / workflows / permissions; the user approves; a new
  `AppVersion` is snapshotted. Clean `propose → approve` loop, 12 read/inspect/
  propose tools.
- The **manifest has no "connection" concept** — the schema literally lists
  connections as roadmap, not present. The builder cannot author integrations or
  tools, and an app cannot reference one.
- Integrations/tools are **platform resources, created only via UI forms**
  (`IntegrationService::create`). But `OAuth2DiscoveryService` and
  `IntegrationRequestExecutor` are production-grade and reusable.

So there are two gaps: the builder can't **author** a connection, and the manifest
can't **use** one. This design closes both.

---

## 2A. Conversational integration creation

Mirror the builder's existing loop — **discover → propose → authorize → verify** —
adding a small set of builder LLM tools that delegate to existing services:

- **`discover_integration(url)`** → wraps `OAuth2DiscoveryService::autoConfigure`.
  Returns a draft config (base_url, auth_type, authorize/token URLs, scopes,
  client registration). For non-OAuth APIs, the builder falls back to asking for
  `base_url` + auth kind, proposal-first (recommends the likely choice).
- **`propose_integration(spec)`** → proposes "create integration X" as an
  approvable change. On approval, `IntegrationService::create` persists it
  (per-tenant, encrypted `auth_config`). Never created silently.
- **Authorization hand-off**:
  - **OAuth2:** the builder surfaces a "Connect X" action; the user completes the
    existing consent flow; control returns to the conversation. **The consent
    click is the human gate** — it fits propose-don't-mutate naturally.
  - **api-key / bearer:** the builder requests the secret through a **secure input
    field in the builder UI** (never typed into plain chat, never sent to the
    LLM), stored encrypted in `auth_config`.
- **`test_connection`** → fires one real request via `IntegrationRequestExecutor`
  and reports success/failure **before** the integration is considered ready.
  Behavioral verification, same spirit as the builder's `SimulateQueryTool`.

Outcome: "conéctate a X" in chat produces a verified, per-tenant connection — the
literal ask, robust (discovery + test), not "paste a JSON".

---

## 2B. Connected objects

### Manifest

An object gains a **source discriminator**:

```
object.source = { type: "internal" }            // today's default: the records table
              | { type: "connected",
                  integration_id: "integ_…",
                  operations: { list, read, create, update },  // each → a request mapping
                  field_map: [ { field_id, external_path, readonly? } ],  // partial-tolerant
                  id_path: "…" }                  // where the external record id lives
```

- `operations` map each CRUD op to an endpoint (method + path template + how to
  locate the collection / id / next-page cursor). Reuses `IntegrationRequest`
  shapes.
- `field_map` ties manifest fields to external field paths; **partial-tolerant**
  (unmapped external fields ignored; unmapped manifest fields render null).
- Internal objects are unchanged — this is additive, no breaking change.

### How the builder authors it (the smart part)

The builder **infers the mapping from a real sample call**: it calls `list`/`read`
once via the executor, sees the actual response shape, and **proposes** the
connected object + field map (proposal-first — the user confirms/edits). That same
sample call **is** the verification. So "muéstrame mis deals de HubSpot como una
tabla" becomes: discover/connect → sample call → proposed connected object → approve.

### Runtime

The app runtime's data layer becomes **source-aware**:
- **Read:** a table/detail over a connected object resolves rows via the
  integration (executor) — live external data — not the `records` table.
- **Write:** create/update from the app UI go through the integration. **The
  logged-in user is the actor** (they clicked save), so UI writes are direct, not
  gated. (Agent writes to a connected object DO go through the propose-don't-mutate
  gate — see §3.)
- **Search / filter / paginate** map to the external API's params where supported;
  degrade gracefully where not.

---

## 3. Multitenancy & security (read this carefully — the boundary shifts)

- **The connection is per-tenant.** The `Integration` is scoped by org/user
  (`HasVisibility`), credentials encrypted. The connected-object *definition*
  lives in the per-tenant app manifest (platform).
- **Connected-object data is NOT our data and NOT under our RLS.** It lives in the
  external system. Our Row-Level Security cannot protect it. Isolation rests
  entirely on: (a) resolving **the tenant's own** integration (never another
  tenant's), (b) the encrypted per-tenant credentials, and (c) the SSRF guard on
  every outbound call. This is a real shift from "RLS protects everything" and
  must be enforced at the connection-resolution layer, deliberately.
- **Write safety:** UI writes = the user acting on their own system of record
  (direct). **Agent** writes = propose-don't-mutate gate (the capability-graph
  rule). Reads carry the `remote / async / may-fail` mark.
- **Blast radius:** the app can now mutate the customer's real CRM/ERP.
  Verification cannot seed demo data into production, so connected-object writes
  default to a **dry-run / preview** during building, and live writes require an
  explicit, connected (non-sandbox) confirmation.

---

## 4. The hard parts (named, not hidden)

- **Partial & drifting external schemas** → mapping is partial-tolerant; the
  builder re-samples to detect drift.
- **Read/write symmetry** → one connected object must read *and* write through one
  coherent mapping, incl. transaction/conflict/rejection handling.
- **Permission mapping** → external API scopes vs the app's own roles; both gate.
- **Pagination / filter / search** over external APIs vary wildly.
- **Latency & cost** → live reads on every page load; decide live vs cached
  (the `remote/async/may-fail` mark exists precisely for this).
- **OAuth consent breaks pure conversation** → a redirect + human click mid-flow;
  the hand-off must feel native to the builder.
- **Verification without a sandbox** → dry-run/preview for writes to the system of
  record.

---

## 5. Build order (wedge-first, each step shippable, contract before code)

Full scope is the destination; we still grow it one verified slice at a time:

1. **Conversational integration creation** — discover/propose/authorize/verify;
   OAuth2 discovery + key/bearer capture. *(The literal ask; foundation.)*
2. **Connected object — read path** — manifest `source: connected`, mapping
   inferred from a sample call, runtime renders live external data in tables/
   detail. *(Huge value alone: the app becomes a live view of the system of record.)*
3. **Connected object — write path** — create/update through the integration from
   the app UI (user is actor), with dry-run/preview safety. *(The app acts.)*
4. **Agent over connected objects** — an agent capability composes reads/writes via
   connected objects under propose-don't-mutate. *(Closes back to the capability
   graph: the agent is just another consumer of the same connection.)*

Each step gets its own contract before code and behavioral verification.

---

## 6. Decisions (resolved)

- **Connection ownership** — the **integration stays a platform resource**
  (per-tenant, as today); the manifest only **references it by id**
  (`source.integration_id`). The manifest gains a *reference*, not ownership, so a
  connection is reusable across apps and the integrations subsystem is not
  duplicated.
- **Secret capture (api-key/bearer)** — a **secure field inside the builder chat**:
  never plain text, never sent to the LLM, stored encrypted. Keeps the experience
  conversational.
- **Connected data freshness** — **live by default**; optional per-object cache
  later. Correctness first; optimize when it hurts (the `remote/async/may-fail`
  mark exists for this).
- **Mapping autonomy** — the builder **auto-infers** the field map from the sample
  call and the user **confirms/edits** (proposal-first).
- **Security boundary (accepted)** — connected-object data lives in the external
  system and is **not covered by our RLS**; isolation rests on resolving the
  tenant's own integration + encrypted credentials + the SSRF guard. This shift is
  accepted as the price of being a control surface over the system of record.

---

## 7. Principles honored (from the vision)

- **Capability graph as a design contract**, grown from the wedge — connected
  objects are the typed read/write capabilities the manifest exposes.
- **Contract before code** — each build step starts with its contract.
- **Propose-don't-mutate** for agent writes; **proposal-first** for every builder
  step.
- **Behavioral verification** — the sample call proves the connection and the
  mapping before anything ships.
- **Parity** — the UI and (later) the agent consume the *same* connection; neither
  gets a capability the other lacks.
