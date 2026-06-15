# Builder Power Contract — Transactional Write-Back (cross-object)

> **Status: CONTRACT ONLY — written before code (Rule 1).** No code ships for this yet.
> This contract defines the *semantics* a future write-back implementation must honor;
> it is deliberately conservative because the honest answer to "atomic writes across our
> DB and an external system" is **"you cannot have them"**, and a contract that pretends
> otherwise would be the most dangerous thing in the product.
>
> **Altitude:** a cross-cutting *semantics contract* for the write path shared by the
> runtime UI (power-#2 connected writes, AppActionController) and the runtime agent
> (power #3, the gate). Never a distributed-transaction engine, never provider-specific
> compensation code.
>
> Resolves vision **open question §13 — "write-back semantics: transactions/conflicts when
> a capability spans internal records *and* an external system of record."** Builds on
> power #2 ([connected objects](./app-builder-connected-objects-contract.md)) and power #3
> ([the runtime agent](./app-builder-runtime-agent-contract.md)).

---

## 0. Intent

A single user action — a form submit, or an approved agent proposal — can carry **more
than one write**: e.g. "create the internal Order record **and** push the deal to
HubSpot **and** decrement inventory in the ERP." Today each write executes
**independently, in sequence, with no transaction** (`AppActionExecutor` loops over the
`actions` array). When one write in the middle fails, the earlier ones have already
landed — and nobody is told. This contract defines what *should* happen instead.

The goal is **not** ACID across heterogeneous stores (impossible — §2). It is: **the
strongest atomicity each store allows, a safe execution order, and — when a cross-store
sequence cannot be made atomic — an honest, legible partial-failure outcome that never
silently leaves the system inconsistent or reports false success.**

---

## 1. Altitude guard (what this is NOT)

- ❌ **Not** a distributed transaction / two-phase-commit coordinator. An external REST
  API is not an XA participant; we will not pretend it is one.
- ❌ **Not** provider-specific compensation ("un-charge the card", "un-send the email").
  Compensation is rarely reliable and always bespoke — building it is the wrong altitude.
- ❌ **Not** a queue/saga framework. No new infrastructure; this is sequencing +
  transaction boundaries + honest reporting over the existing executor.
- ✅ A set of **rules** the write path obeys: a transaction boundary where one is
  possible, a defined execution order, optimistic conflict checks where the API supports
  them, and a `partially_applied` outcome that is surfaced, never swallowed.

If an implementation reaches for cross-system rollback magic or per-provider undo, stop —
the design is wrong.

---

## 2. The hard truth (the locked premise)

There are two kinds of store behind a write:

- **Internal records** — Postgres (tenant schema, RLS). **Transactional and
  reversible:** N internal writes can be wrapped in one DB transaction and rolled back
  cleanly.
- **Connected objects** — an external system reached over HTTP (power #2). **Not
  transactional, not reversible by us:** once a `POST /deals` returns 201, that deal
  exists; we cannot undo it, and the external API may have no idempotency key, no
  rollback, no compensation.

Therefore:

> **Atomicity is possible *within* the internal store, and *impossible* across the
> internal store and an external system in the general case.** Every rule below follows
> from this. We do not weaken it with optimism.

---

## 3. The model

Classify each proposal/action-sequence by the stores it touches:

| Class | Stores touched | Guarantee |
|---|---|---|
| **Internal-only** | records only | **Atomic.** Wrap the whole sequence in one DB transaction — all writes commit or none do. |
| **External-only** | one connected object | **Per-write only.** Each external write is its own unit; a multi-write external sequence is best-effort ordered (no cross-call atomicity exists). |
| **Cross-store** | records **and** ≥1 external | **No atomicity.** Ordered best-effort with an internal transaction barrier (§4) and an honest partial-failure outcome (§5). |

**Default posture: avoid cross-store proposals.** The safest, and the current contract's
stance ("one gated action at a time"), is to keep a proposal within a single store. A
cross-store proposal is allowed only when the use case genuinely requires it, and then it
is governed by §4–§5. The runtime agent's proposal builder SHOULD prefer splitting work
into separate, individually-legible single-store proposals over one cross-store batch.

---

## 4. Execution order & the transaction barrier (cross-store)

When a sequence must span stores, order is chosen to **minimize the blast radius of a
failure**, and the internal transaction is used as a barrier so an external failure
leaves *no* internal residue:

1. **Validate everything first.** Run every action's validation (field rules, manifest
   constraints, permission/grant checks) before *any* side effect. A validation failure
   aborts the whole sequence with nothing written.
2. **Open the internal DB transaction** but do **not** commit yet. Apply the internal
   writes inside it.
3. **Execute the external writes.** If an external write fails → **roll back the internal
   transaction** (it never committed) and report failure (§5). The external writes that
   already succeeded *before* this one cannot be rolled back → the outcome is
   `partially_applied`, not `failed` (§5).
4. **Commit the internal transaction** only after all external writes succeed.

This makes the common cross-store shape — "write internally **and** mirror externally" —
safe in the most likely failure case (the external call fails): the internal side rolls
back, so the two stores stay consistent. The unavoidable danger window is **multiple
external writes**, where the second failing cannot undo the first — handled by §5, not
hidden.

> **Single external write + any internal writes ⇒ effectively atomic** (the internal txn
> is the barrier). **Two or more external writes ⇒ not atomic** — surfaced honestly.

---

## 5. Partial failure — honest, legible, never silent

The outcome of a sequence is one of:

| Outcome | Meaning | UI / agent surface |
|---|---|---|
| `applied` | every write succeeded | normal success |
| `failed` | aborted with **no** persisted side effect (validation failure, or failure before/at the first external write with the internal txn rolled back) | error; safe to retry |
| `partially_applied` | some external writes landed, a later one failed; internal txn rolled back | **explicit reconciliation state** — list exactly which writes succeeded and which did not; never reported as success, never silently retried |

Rules:

- **No false success.** A sequence is `applied` only if *all* writes succeeded. Anything
  else surfaces its true state.
- **No silent retry of a partial.** `partially_applied` is handed to the human with the
  concrete list (mirrors the runtime agent's "fail → gated" safeguard). Auto-retry could
  double-apply a non-idempotent external write.
- **Legibility (vision §8).** The effect is recorded per-write (which succeeded, which
  failed, with the external id/error), so "what just happened" is answerable after a
  partial failure — the whole point of the control-surface thesis.

---

## 6. Conflicts (optimistic, best-effort)

For **updates**, a write may race another editor:

- **Internal:** use the record's `updated_at` / a version column as an optimistic guard —
  reject the write if the row changed since it was read, surfacing a conflict the user
  resolves. (Cheap; internal store fully supports it.)
- **External:** use the API's concurrency primitive **where it exposes one** (ETag /
  `If-Match`, a version field, a `updated_at` precondition). **Degrade gracefully where it
  does not** (most don't) — last-write-wins, documented, not silently "safe". The
  `remote / may-fail` mark already says this write is best-effort.

No global lock, no cross-store serialization — that is the distributed-transaction trap
of §1.

---

## 7. Security & tenancy (unchanged, restated)

The write path's existing guarantees hold unchanged: every write runs as the acting user,
internal writes under RLS, external writes through the tenant's own integration + SSRF
guard (power #1/#2). The transaction barrier and partial-failure reporting add **no new
trust boundary** — they only sequence and report writes the user is already authorized to
make.

---

## 8. Behavioral acceptance

Tested with seeded internal records + `Http::fake` for connected sources:

1. **Internal-only atomicity** — a multi-write internal sequence where the 2nd write
   fails leaves **zero** records persisted (the txn rolled back).
2. **Cross-store barrier** — internal write + one external write, external returns 5xx ⇒
   `failed`, **no** internal record persisted (internal txn rolled back), external never
   half-committed.
3. **Cross-store success** — internal write + external write both succeed ⇒ `applied`,
   internal committed, external called once.
4. **Partial failure** — two external writes, the 2nd fails ⇒ `partially_applied`, the
   1st's success and the 2nd's failure both reported; internal txn (if any) rolled back;
   **not** reported as success; **not** auto-retried.
5. **Validation-first** — an invalid action aborts the whole sequence before any side
   effect (no internal write, no external call).
6. **Conflict** — an internal update against a stale version is rejected as a conflict;
   an external update sends the API's precondition when available and degrades cleanly
   when not.
7. Nothing in the implementation is provider-specific or attempts cross-system rollback.

---

## 9. Build order (each shippable, contract-before-code)

1. **Internal-only atomicity** — wrap a multi-action sequence's internal writes in one DB
   transaction in `AppActionExecutor` (today they are independent). *Pure win, no
   external complexity, fixes the silent-partial bug for the common case.*
2. **Cross-store barrier + outcome reporting** — the txn-barrier ordering (§4) and the
   `applied` / `failed` / `partially_applied` outcome (§5), surfaced in the action
   response and the agent's action card.
3. **Optimistic conflict checks** (§6) — internal version guard first; external
   preconditions where supported.

---

## 10. Non-goals

- **ACID across stores / two-phase commit** — impossible (§2); never attempted.
- **Provider-specific compensation / rollback** — wrong altitude (§1).
- **A saga/queue framework** — sequencing over the existing executor, not new infra.
- **Auto-resolving a `partially_applied` outcome** — it is a human reconciliation state by
  design; automating it risks double-applying non-idempotent external writes.

---

## 11. Why this is the right shape

It tells the truth: the value of a control surface over the customer's real system of
record is real, and so is the fact that the external system is not transactional. Pretending
otherwise — fake rollbacks, silent retries, "success" on a partial — is precisely how a
self-serve product corrupts a customer's system of record and loses their trust. This
contract gives the strongest guarantee each store actually supports, a safe order that makes
the common case consistent, and — where consistency is genuinely impossible — an honest,
legible partial state the human can reconcile. That honesty *is* the feature.
