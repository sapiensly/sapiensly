# Builder Power Contract — Connected Objects

> **Status:** Contract (pre-implementation). Written before code (Rule 1).
> **Altitude:** a *power of the App Builder* + a manifest capability + runtime
> support — never an app, never provider-specific. A connected object is just a
> **custom object whose backing store is an external system**. The "HubSpot deals
> table" is a user output, created by talking to the builder.
>
> Power #2 of the connected-objects design
> ([design](./app-builder-connected-objects-design.md)). Builds on power #1
> ([create an integration](./app-builder-create-integration-contract.md)) — a
> connected object references an integration the builder created in conversation.

---

## 0. Intent

Give the App Builder the power to author, **in conversation**, a **connected
object**: a manifest object whose records live in an external system of record
(reached through a power-#1 integration) instead of the internal `records` store.
A page that lists or edits such an object then reads — and writes — **live**
against the customer's system. This is what turns a built app from a CRUD island
into a control surface over the system the business already runs on.

Provider-agnostic by construction: the same power produces a connected "Deals"
object over HubSpot, a "Tickets" object over Zendesk, or rows over a niche ERP —
no per-provider code.

---

## 1. Altitude guard (what this is NOT)

- ❌ Not a HubSpot/Zendesk/etc. object or sync job.
- ❌ Not a bespoke table — a connected object is a **custom object** in the
  manifest; nothing about it is hand-coded per provider.
- ✅ A manifest capability (a new `source` on objects), builder tools to author it,
  and a source-aware runtime that serves it through the existing integration executor.

If implementing this requires provider-specific code or a bespoke table, the
design is wrong — stop and reshape it.

---

## 2. The manifest shape (additive, no breaking change)

An object gains a **source discriminator**; absent ⇒ `internal` (today's behavior):

```
object.source =
    { "type": "internal" }                       // records store (default)
  | { "type": "connected",
      "integration_id": "integ_…",               // a power-#1, authorized integration
      "operations": {                            // each maps to an integration request
        "list":   { method, path, collection_path, page_param? },
        "read":   { method, path },              // path may template {id}
        "create": { method, path },              // omit to make the object read-only
        "update": { method, path }               // omit to make the object read-only
      },
      "id_path": "…",                            // where the external record id lives
      "field_map": [ { field_id, external_path, readonly? } ]  // partial-tolerant
    }
```

- `field_map` ties manifest fields to external response paths; **partial-tolerant**
  (unmapped external fields ignored; unmapped manifest fields render null).
- Omitting `create`/`update` yields a **read-only** connected object — the common,
  safest first shape.
- Internal objects are untouched.

---

## 3. How the builder authors it (the smart, verified part)

Connected-object creation **is a manifest change**, so it rides the builder's
existing `propose_change → approve` loop (unlike power #1's integration, whose gate
is authorization). The flow in conversation:

1. **Ensure a connection** — if no suitable integration exists, use power #1 to
   create + verify one first.
2. **`sample_endpoint`** (new builder tool) — call a `list`/`read` endpoint through
   the integration (via `IntegrationRequestExecutor`) and return the **real
   response shape**. This is both schema discovery and verification.
3. **Infer + propose** — the builder infers `id_path` and a `field_map` from the
   sample and **proposes** (`propose_change`) a new object with `source:
   connected`. The user **confirms/edits** the mapping (proposal-first), then
   approves — materializing a new `AppVersion`, exactly like any manifest change.

The sample call is the behavioral proof that the mapping is real before it ships.

---

## 4. Runtime (the heaviest engineering)

The app runtime's record data layer becomes **source-aware**:

- **Read path (first slice):** a table/detail over a connected object resolves rows
  through `IntegrationRequestExecutor` (live external data), maps them via
  `field_map`, and renders them like any object. Search / filter / sort / paginate
  map to the external API's params where supported, and degrade gracefully where
  not.
- **Write path (second slice):** create/update from the app UI go through the
  integration. **The logged-in user is the actor** (they clicked save) → direct
  write. An **agent** writing to a connected object goes through the
  propose-don't-mutate gate (the capability-graph rule), not direct.

Reads carry the `remote / async / may-fail` mark: an external outage yields an
error state in the UI, never a crash.

---

## 5. Data residency & security (the locked decisions)

- **Passthrough live by default — we store none of the external data.** Isolation
  is structural: every call uses the **tenant's own** integration (power #1
  guarantees tenant-scoped resolution) + the SSRF guard. There is no row of theirs
  in our DB to leak, which is the *safer* posture for the self-serve ICP.
- **Any materialization is a custom object under RLS.** If we ever cache/snapshot
  connected data (later, per-object), it becomes **records in the tenant schema
  (RLS)** — never a bespoke table, never `platform`, never a shared store.
- Connected-object data is **not** covered by our RLS (it isn't ours); that boundary
  shift is accepted as the price of being a control surface, and is bounded by
  tenant-scoped credentials + SSRF.

---

## 6. Behavioral acceptance

Tested with `Http::fake` over a fake integration:

1. **Sample** — `sample_endpoint` returns the real collection shape; the builder
   proposes an object whose `field_map` matches it.
2. **Read** — listing a connected object returns external rows mapped to manifest
   fields; an unmapped field is null (partial-tolerant), not an error.
3. **No internal storage** — reading a connected object writes **nothing** to the
   `records` store (passthrough proof).
4. **Write (slice 2)** — a create/update from the UI reaches the external system
   with the integration's auth applied; the response id is captured.
5. **Degradation** — an external 5xx/timeout surfaces an error state; no crash, no
   silent empty success.
6. **Tenant isolation** — a connected object resolves only the owning tenant's
   integration.
7. Nothing in the implementation is provider-specific.

---

## 7. Failure modes

| Condition | Behavior |
|---|---|
| Partial / drifting external schema | partial-tolerant `field_map`; re-sample to detect drift |
| External API down / 5xx / timeout | `remote/may-fail` → UI error state, never a crash |
| Unmapped manifest field | renders null |
| Write rejected by external system | surfaced inline; no false success |
| Integration unauthorized / missing | object shows "needs connection"; links back to power #1 |

---

## 8. Build order within power #2 (each shippable, contract-before-code)

1. **Read path** — `sample_endpoint` tool + `source: connected` (read-only:
   `list`/`read` only) + source-aware read runtime. *The app becomes a live view of
   the system of record — huge value alone.*
2. **Write path** — `create`/`update` operations + source-aware write runtime (UI =
   user is actor; agent = gated).

---

## 9. Non-goals (this power)

- The **agent** composing reads/writes over connected objects — a later capability
  on top of the graph.
- A generic **sync/cache engine** — passthrough first; caching (as custom objects)
  comes only when latency/cost demands it.
- Provider-specific connectors or apps.

---

## 10. Why this is the right next power

Power #1 created the connection; power #2 makes the app **use** it — the step that
delivers the "control surface over the system of record" thesis. It is provider-
agnostic (composition, not bespoke code), rides the existing manifest
propose→approve loop and the existing integration executor, and keeps connected
objects as first-class custom objects — consistent with "all app data is a custom
object."
