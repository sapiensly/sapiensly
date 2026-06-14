# App Builder — Vision: Agentic Power with the UI as a Legibility Surface

> **Status:** North‑star / design philosophy (forward‑looking, not a description of the
> current implementation). It sets the direction the App Builder is moving toward and the
> principles that should guide every decision along the way.

---

## 1. The thesis

We are not building an *app builder*. We are building a **capability compiler**.

The output of a conversation is not "an app" — it is a **typed graph of capabilities** over the
business's real system of record. The UI is one *rendering* of that graph; the agent is another.
This single reframe reorders almost everything below.

The product bet that follows: **agents that execute, not chatbots.** An app is *a capability
graph with a default agent and an optional UI* — and that is a different category than the
canvas-first low‑code tools (Retool, Bubble) we are measured against.

---

## 2. The core primitive: the capability graph

A capability is a typed, contract‑bearing unit of work. The manifest evolves from
`objects / pages / workflows` into a **graph of capabilities**, where each node carries its full
contract:

- **Shape:** input/output types (tolerant of partial/uncertain typing — see connected objects).
- **Direction:** read, write, or both. Read/write must be *symmetric* through the same contract.
- **Effects & preconditions:** what it changes, what must be true first.
- **Policy:** permissions / RLS, who may invoke it, autonomy level (safe vs approval‑gated).
- **Cost & latency:** first‑class, because every consumer (UI, agent, verifier) pays for it.

Three consumers read from the same contract:

| Consumer | Role |
|---|---|
| **Generated UI** | A projection of capabilities — the *legibility* surface. |
| **Embedded agent** | A consumer of the same capabilities — the *power* surface. |
| **Verifier** | Proves each capability behaves (see §6). |

**Why graph‑first, UI second:** the hard problem stops being "generate pretty screens" and
becomes "model the capability correctly." Once the graph exists, the UI derives from it and the
agent gets its tools for free.

---

## 3. Connected objects — the read path is the unlock

The biggest leap is not the write path (charge, notify, query an ERP). It is the **read path**:
external systems as the *backing store of the manifest's own objects* ("connected objects").

Without it you build a CRUD island — an Airtable with buttons. With it, the app stops being yet
another silo and becomes a **control surface over the system of record the business already has.**
A page lists and edits directly against the customer's database or API — no ETL, no copy.

The substrate already exists in the platform and should be unified under one notion of a
connected object:

- **BYODB** (`byodb_runtime` connection, `cloud_providers`) — the tenant's own database.
- **Integrations** (REST / GraphQL / MCP) — external services.

What makes this *platform* work, not a demo:

- **Read/write symmetry.** A connected object must be readable *and* writable through one
  contract, which drags in transaction semantics, conflicts, and external‑rejection handling.
- **Permission mapping.** The external system's auth vs our RLS/roles. We become responsible for
  not corrupting the system of record — the blast radius is real.
- **Partial, drifting schemas.** Rarely will the schema be complete or stable; capabilities must
  tolerate partial typing via adapters, not assume perfect FKs.

---

## 4. The agent as a runtime primitive (not a bolted‑on chatbot)

The agent is what separates us from low‑code. But it only delivers if it is a **consumer of the
capability graph**, not a fourth pillar built on the side:

- **It gets its tools for free.** Every capability is automatically an affordance for the agent.
  You don't wire agent tools separately — *the graph is the toolset.* This dissolves the usual
  "make the agent a primitive" project into a consequence of the graph existing.
- **It is dependent, not parallel.** An embedded agent is only "power" if it has real capabilities
  with real consequences. Without connected objects (read+write) and verification underneath, the
  in‑app agent *is* the chatbot the thesis rejects. **Order matters: connected objects +
  verification first; the agent on top.**
- **The safety gate already exists.** An agent that writes to the system of record is powerful and
  dangerous in equal measure. The correct safety primitive is the **action‑proposal** pattern we
  shipped in chat: the agent *proposes* a mutation with a preview of its effect, and it executes
  after approval (or autonomously only on capabilities marked safe). That turns "an agent that
  executes" into something deployable, not a roulette.

There is an elegant recursion that validates the model: *the builder is an agent that grows the
graph by conversation; the apps contain agents that operate on the graph.* Same primitive at
build‑time and run‑time — which is what "simple conversations" means, made coherent end to end.

---

## 5. The UI as a legibility surface (power vs confusion)

An agent‑first surface is, by default, **both more powerful and more confusing.** Here confusion
is a *design failure*, not an inherent property — and the enumerable graph is exactly what lets us
kill it without giving up the power.

The two real failure modes are legibility problems, both solved by the graph (not by prettier UI):

1. **"What can I ask it?"** — the blank box. Because the graph is *enumerable*, we never need to
   present an empty prompt: the agent always surfaces what it can do, suggests the next step, and
   constrains to the possible. (This is *proposal‑first* again.)
2. **"What just happened?"** — the invisible effect. Because every mutation goes through the
   action‑proposal preview, the user sees the effect before it happens and can revert it.

**The UI is not the agent's rival — it is its legibility layer.** The agent *acts*; the UI
*reflects* state, history, and data. They stay in sync because both read the same graph. The user
points‑and‑clicks for precise/bulk/spatial work (edit 200 rows, scan a dashboard) and talks to
the agent for ambiguous/cross‑object/novel work ("reconcile the late orders and notify the
customers"). Confusion comes from forcing the wrong surface for the task; the graph lets us offer
both and let the task choose.

**Match the surface to the user, too:** build‑time users are covered by proposal‑first; run‑time
users need affordances + visible state; power users want the agent immediately while casual users
need the UI as a safety net while trust builds. Hence a **trust ramp**: start with the agent
proposing and the human approving (low autonomy, maximum legibility), and graduate to autonomous
only on capabilities marked safe.

> **Principle:** *an agent is only as trustworthy as what it can do — and what it already did — is
> legible.* The enumerable graph gives us that legibility for free.

---

## 6. Verification: behavioral, not schema‑deep

Validating the manifest against a schema proves it is *well‑formed*; it does not prove the app
*works*. The loop must verify **behavior**:

1. **Generate** the capability (or change).
2. **Seed** demo data.
3. **Run** the workflows / capabilities.
4. **Assert** real outcomes ("create an order → a notification fires").
5. **Show** the app working with those outcomes.
6. **Self‑repair** when an assertion fails, before handing anything over.

This is the line between a flashy demo and a trustworthy app. Behavioral tests attach to
**capabilities**, not screens — which is clean precisely because the graph is the single target.

**Connected objects raise the stakes.** You cannot seed test orders into a production ERP. So
behavioral verification *forces* a sandbox / dry‑run / read‑only‑first posture for capabilities
that touch the system of record. Verification and connected objects are two ends of the same hinge.

---

## 7. Proposal‑first as an invariant

Never a blank prompt. The system always advances with a concrete bet the user edits: *"I'm going
to do X — correct me."* Applied across the whole lifecycle, not just discovery: every ambiguous
step is a recommended default plus a one‑tap override. This is the same shape as the
action‑proposal gate, which makes the builder and the running app *feel the same*.

---

## 8. What this commits us to

- **The contract is the product.** Capability contracts (types, read/write, effects, policy, cost)
  stop being metadata and become where the value lives — three consumers depend on them.
- **The parity invariant.** Anything the UI can do, the agent can do, and vice versa, because both
  are pure consumers. Hard rule: **the UI layer consumes capabilities, it never defines them.** The
  moment logic lives only in a screen, the graph stops being the source of truth and the agent
  silently loses parity. This needs a lint/guardrail, not good intentions.
- **The default‑renderer paradox.** "UI is secondary" does **not** mean low‑effort. Buyers judge
  with their eyes; a generic auto‑render reads as "unfinished." Because it is secondary, the
  *default* renderer must be opinionated and polished. Secondary = non‑bespoke, not neglected.
- **Capability granularity is the central design problem.** Too coarse → the agent can't compose;
  too fine → the graph explodes and both surfaces get noisy. External systems make this harder by
  exposing awkward "verb sizes."

---

## 9. Build sequencing

The order of construction inverts relative to a UI‑first builder:

1. **Capability graph model + contracts.**
2. **Connected objects** (read + write through one contract) over BYODB + integrations.
3. **A strong default renderer** (the legibility surface).
4. **The behavioral verification harness** (targets capabilities; sandbox/dry‑run for external).
5. **The embedded agent** — falls out almost for free as a consumer of the graph.
6. **Bespoke UI editing — last, and optional.**

---

## 10. Why this is defensible

Canvas‑first tools (Retool, Bubble) give you a canvas. We give a **capability graph over the
customer's real system of record**, with two surfaces (human and agentic) and a verification loop
that backs them. They were born UI‑first and cannot retrofit this without rewriting their core.
The moat is the contract + the connected‑object integration + the verification + the dual surface
— not any single screen.

---

## 11. Open questions

- **Granularity:** what is the right "verb size" for a capability, and how do we keep it stable as
  connected objects expose messy external APIs?
- **Write‑back semantics:** transactions/conflicts when a capability spans internal records *and*
  an external system of record.
- **Autonomy policy:** how does a capability earn the "safe / autonomous" mark, and who decides?
- **Primary surface drift:** for which app classes does the agent become the primary surface and
  the UI the fallback — and how do we let an app evolve along that axis without a rebuild?
