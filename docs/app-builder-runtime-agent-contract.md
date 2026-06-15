# Builder Power Contract — The Runtime Agent over the Capability Graph

> **Status: READ + WRITE SLICES IMPLEMENTED.** The runtime agent ships: a `manifest.agent`
> block + auto-derived toolset (`RuntimeAgentToolset`), source-agnostic read tools
> (`describe_capabilities`/`query_object`/`aggregate_object`), tenant-scoped conversation
> storage, a streaming service/job over Reverb, the end-user chat panel, the gated
> `propose_*` write tools recording proposals (executing nothing), and the
> approve/dismiss gate that runs an approved proposal through the shared
> `AppActionExecutor` (the same write path the UI uses). Behavioral tests cover toolset
> derivation/scoping, reads (internal + connected), the load-bearing propose-doesn't-mutate,
> approve-executes, and dismiss.
> **Plus the autonomy engine (§5):** `manifest.agent.safe` per-capability marks +
> `autonomy: "safe"` let a safe-marked **internal create/update** — or an explicitly
> safe-marked **workflow** — auto-execute without approval (`AutonomyPolicy` +
> `RuntimeAgentService::finalizeProposals`), with baked-in safeguards — delete never
> auto, connected always gated, a failed auto-run falls back to gated, every auto-run
> recorded (`auto_previews`). Default-deny.
> **Deferred (non-goals, §10):** the general capability registry/compiler/parity-lint
> (wedge-first, until N justifies it) and cross-object transactional write-back.
>
> Written before code (Rule 1). This contract defined Power #3.
>
> **Altitude:** a *power of the App Builder* — the **power surface** over the same
> capability graph the generated UI projects (vision §5, §7). The runtime agent is a
> **consumer** of capabilities, never a definer of them (the parity invariant, vision
> §10). It is born as a typed capability whose every write is **propose-don't-mutate**
> (Rule 2 — the one non-negotiable). Never an app, never provider-specific.
>
> Power #3 of the App Builder. Builds on power #1
> ([create an integration](./app-builder-create-integration-contract.md)) and power #2
> ([connected objects](./app-builder-connected-objects-contract.md)) — the agent reads
> and writes over the objects those powers produced. Grounded in the
> [vision](./sapiensly-app-builder-vision.md) (§7 the agent as a runtime primitive, §3
> the trust ramp, §9 verification).

---

## 0. Intent

Give a built app an **embedded agent its end-users talk to**, that **composes reads
and writes over the app's own manifest capabilities** — internal records, connected
objects (power #2), and workflows. Reads run **live and directly**. Writes **never
mutate inline**: the agent *proposes* an effect, the human *previews and approves* it,
and only then does the existing write path execute it.

This is the step that turns a built app from a screen you operate into a **system you
can instruct** — and it is what separates us from low-code (vision §7, §12). The same
primitive runs at build-time (the builder agent grows the graph) and at run-time (the
app agent operates the graph) — the recursion that validates the model (vision §7).

Provider-agnostic and app-agnostic by construction: the agent's toolset is **derived
from the manifest**, so a CRM app and a tickets app get a working agent with zero
per-app, per-provider code.

---

## 1. Altitude guard (what this is NOT)

- ❌ Not a chatbot bolted onto the app. Without capabilities + a gate underneath, an
  in-app agent *is* the chatbot the thesis rejects (vision §7). The agent is only as
  good as the typed capabilities it consumes.
- ❌ Not an autonomous writer. A self-serve agent with direct write access to the
  customer's system of record is the **anti-ICP** risk (vision §3), not the flagship.
- ❌ Not a place where capabilities are defined. The agent **consumes** the same
  capabilities the UI does; it never invents one the UI can't perform (vision §10
  parity invariant). If a proposed action has no UI equivalent, the design is wrong.
- ✅ A runtime affordance auto-derived from the manifest, a propose-don't-mutate gate
  reused from the patterns already shipped (chat `action_proposal`, builder
  `propose_change`), and a thin runtime endpoint — composition, not new infrastructure.

If implementing this requires provider-specific tools, a bespoke per-app agent, or a
write that bypasses the gate, stop and reshape it.

---

## 2. The capability → tool mapping (auto-derived, free at the toolset level)

Every manifest capability becomes an agent tool automatically (vision §7 — "free at
the toolset level"). The mapping is mechanical and source-agnostic:

| Manifest capability | Agent tool | Direction | Backed by (existing) |
|---|---|---|---|
| object (internal or connected) | `query_object(object_id, filter?, sort?, limit?, offset?)` | **read** | `RecordQueryService` / `ConnectedObjectReader` |
| object | `aggregate_object(object_id, aggregation, field_id?, filter?)` | **read** | `RecordQueryService::aggregate` |
| object with `create`/`update` | `propose_create_record` / `propose_update_record` | **write (gated)** | `RecordWriteService` / `ConnectedObjectWriter` |
| object (internal) | `propose_delete_record` | **write (gated)** | `RecordWriteService::delete` |
| workflow | `propose_run_workflow(workflow_id, input)` | **write (gated)** | `WorkflowEngine` |
| (the graph itself) | `describe_capabilities()` | **read** | the manifest |

Reads are **source-agnostic** — power #2 already unified internal records and connected
rows into the same `{id, data}` shape, so the read tools work over both with no branch.
Every read tool carries the **`remote / async / may-fail`** mark in its contract from
day one; for connected objects it is literally true, and a remote outage degrades to a
tool error the agent can report, never a crash (the cost model is deferred — vision §2).

The write tools are **propose-only** (see §4). They do not name `create_record` etc.
directly; they are `propose_*` so the shape on the wire makes the gate impossible to
forget.

---

## 3. How the builder authors it (the agent is a manifest capability)

Like every power, adding an agent to an app is a **manifest change** and rides the
builder's existing `propose_change → approve` loop. In conversation with the builder:

1. **Declare the agent** — an additive, optional `manifest.agent` block:
   ```
   manifest.agent =
     { "enabled": true,
       "name": "Assistant",
       "instructions": "…",                 // the runtime system prompt
       "capabilities": {                     // which tools the agent may use
         "read":  ["obj_…", "obj_…"] | "all",
         "write": ["obj_…"] | []             // empty ⇒ read-only agent (safest default)
       },
       "autonomy": "propose",                // "propose" (default, all gated) | "safe" (honor marks)
       "safe": [ { "object_id": "obj_…", "actions": ["create","update"] } ] }  // per-capability auto-execute (§5)
   ```
2. **Default safest shape** — `write: []` (a read-only data copilot) is the common
   first agent. Granting a write capability is an explicit manifest edit the user
   approves, visibly widening blast radius.
3. The builder proposes the block; the user approves → a new `AppVersion`, exactly like
   any manifest change. The runtime then serves the agent.

The agent **cannot exceed what the manifest grants** — capability scope is a property
of the capability, not just route middleware (vision §10 invariant #4).

---

## 4. Runtime — the gate is the whole point

The runtime agent lives behind a built-app endpoint (mirrors `BuilderAiService` but
its tools are the manifest capabilities, not the builder tools). It streams over Reverb
like the existing agents (queue job → broadcast deltas; never `response()->stream()`).

- **Read path:** the agent calls read tools directly and answers over live data
  (internal + connected). No gate — reads don't mutate. This is the entire first slice
  and is valuable alone: a copilot over the app's system of record.

- **Write path — propose-don't-mutate (Rule 2, the one non-negotiable):** a write tool
  **never executes**. It records a **proposal**: the resolved action (type, object,
  values / record id / workflow input) plus a **human-readable preview** of the effect.
  The proposal surfaces in the conversation as an **action proposal** (reusing the chat
  `action_proposal` message shape). The human **approves** → the proposal executes
  **through the existing `AppActionController` write path** (so internal records,
  connected writes, and workflows all run *unchanged*, including power #2's connected
  write and its read-only/error handling). The human **dismisses** → discarded, nothing
  happens.

  **Every agent write is gated — internal records included.** Power #2 made *UI* writes
  direct because *the user is the actor*; an **agent** write has no human in the act, so
  it is gated regardless of source. This is the invariant that **composes**: every
  future capability inherits the shape (vision §2).

- **The actor is the approving human.** The approved action executes as the logged-in
  user, through the same RLS/tenant scope and permissions a manual click would use. The
  agent can never do more than the human could do by hand.

Reads carry the `remote/async/may-fail` mark; a connected read outage yields an agent
error message, never a crash.

---

## 5. Trust ramp & autonomy (the motion's hard rule)

Tied to the PLG / self-serve motion (vision §3): **start propose-and-approve for every
write** — low autonomy, maximum legibility. Autonomous (ungated) execution is allowed
**only** on capabilities explicitly marked `safe` — granularity is per-capability, not
agent-wide (a blanket "autonomous agent" is the anti-ICP blast radius, vision §3).

**The autonomy engine (`AutonomyPolicy` + `RuntimeAgentService::finalizeProposals`):**

- **Master switch.** `manifest.agent.autonomy` is `"propose"` (default — everything
  gated) or `"safe"` (honor the marks below). In `"propose"` the engine is fully off.
- **Per-capability marks.** `manifest.agent.safe` holds two entry shapes: an object mark
  `{ object_id, actions: ["create"|"update"] }` (a record create/update auto-executes iff
  its `(object_id, action)` is listed) and a workflow mark `{ workflow_id }` (a
  `run_workflow` auto-executes iff that workflow is listed). **Default-deny:** anything
  unlisted stays gated.
- **Non-negotiable safeguards, baked in (no manifest can override):**
  1. **delete never auto-executes** — enforced by the policy *and* the schema (the object
     `actions` enum is `create`/`update` only). A workflow auto-runs only when its
     `workflow_id` is explicitly listed.
  2. **Connected (external system) writes are always gated** — only internal records,
     which live under RLS and are reversible, can auto-run.
  3. **A failed auto-run falls back to gated** — it is never retried silently; it
     becomes a pending proposal for the human to decide.
  4. **Every auto-run is recorded and visible** (`auto_previews` on the message, shown
     as "✓ Done automatically") — never an invisible mutation (vision §8).
- **Mixed turns** keep the auto part visible *and* surface the rest for approval.

Trust is earned, never assumed: an app ships with a read-only agent or a
propose-only writer, and graduates only by explicit, approved manifest edits that add
`safe` marks — each one visibly widening blast radius.

---

## 6. Legibility — the two failure modes are designed out (vision §8)

- **"What can I ask it?"** — never a blank box. The graph is enumerable, so the runtime
  surfaces what the agent can do (from `describe_capabilities`) and suggests next steps.
- **"What just happened?"** — never an invisible effect. Every mutation goes through the
  preview before it happens and leaves an `action_result` in the thread after. The
  effect is seen before it is real and is legible after.

The UI is the agent's **legibility layer**, not its rival: the agent acts, the UI
reflects state/history/data, both read the same graph (vision §8).

---

## 7. Data residency, security & tenancy (locked)

- **Same scope as a manual action.** Runtime requests are authenticated;
  `BindTenantContext` + `forAccountContext` scope every read and write to the user's
  tenant (RLS for internal records; the tenant's own integration + SSRF guard for
  connected — power #1/#2 guarantees). The agent adds **no new trust boundary**: it is a
  driver of the same gated write path.
- **Passthrough preserved.** Connected reads/writes store nothing locally (power #2).
- **No capability escalation.** The agent's toolset is exactly what `manifest.agent`
  grants; an ungranted object is not a tool, so it cannot be read or proposed against.
- **Prompt-injection containment.** Because writes cannot execute without human
  approval of a legible preview, a hostile instruction (e.g. inside a connected row the
  agent read) can at worst *propose* — it cannot mutate. The gate is the containment.

---

## 8. Behavioral acceptance (tests attach to capabilities, vision §9)

Tested with `Http::fake` for connected sources and seeded internal records:

1. **Toolset derivation** — an app's agent exposes exactly the read/write tools its
   `manifest.agent.capabilities` grant; an ungranted object yields no tool.
2. **Read** — the agent answers over live data; connected and internal objects read
   through the same path; a connected outage surfaces as a tool error, not a crash.
3. **Propose-don't-mutate (the load-bearing test)** — a write instruction produces an
   **action proposal and changes nothing** in the records store or the external system
   until approval. This is the test that proves Rule 2 holds.
4. **Approve → execute** — approving the proposal runs it through `AppActionController`;
   an internal create persists a record, a connected create reaches the external system
   with auth applied (reusing power #2), a workflow runs.
5. **Dismiss** — dismissing discards the proposal; no effect, no false success.
6. **Scope** — the approved action executes as the user, under RLS; it cannot touch
   another tenant's data nor exceed the user's permissions.
7. **Read-only agent** — an agent with `write: []` exposes no write tools and cannot
   propose a mutation.
8. Nothing in the implementation is provider-specific or per-app.

---

## 9. Build order within power #3 (each shippable, contract-before-code)

1. **Read slice** ✅ — runtime agent endpoint + conversation/message storage + the read
   tools (`query_object`, `aggregate_object`, `describe_capabilities`, source-agnostic
   over internal + connected) + Reverb streaming + the `manifest.agent` block
   (read-only) + the end-user chat panel. *The app gains a copilot over its own system of
   record — valuable alone, zero write-blast-radius.*
2. **Propose-write slice** ✅ — `propose_*` write tools → action proposal with preview;
   approve/dismiss endpoints; approval executes through the shared `AppActionExecutor`
   (internal, connected, and workflow writes, all gated) + the approval ActionCard.
   *The app gains an agent that takes actions, safely.*

---

## 10. Non-goals (this power)

- **A general capability registry / compiler / parity lint** — emerges when N justifies
  it (vision §11.4); discipline enforces parity at small N.
- **Cross-object transactional write-back** — when one proposal spans internal records
  *and* an external system of record (vision open question §13). One gated action at a
  time for now.
- **Provider-specific tools or a bespoke per-app agent.**

---

## 11. Why this is the right next power

Powers #1 and #2 built the connection and made the app *read and write* the system of
record. Power #3 is the **payoff**: the agent that *operates* it — "agents that execute,
not chatbots" (vision §1) — delivered with the exact safety the self-serve ICP requires,
because every write is propose-and-approve by default. It is provider-agnostic
(composition over the graph, not bespoke code), rides the manifest propose→approve loop
and the gates already shipped, and honors the parity invariant that is the moat
(vision §12): anything the agent does, the UI can do, because both are pure consumers of
the same capabilities.
