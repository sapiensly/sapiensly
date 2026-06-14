# App Builder — Vision: Agentic Power with the UI as a Legibility Surface

> **Status:** North‑star / design philosophy (forward‑looking). **Wedge‑first.** The capability
> graph is the direction we validate *toward*, not a rewrite we do *before* validating. This doc
> leads with the discipline that protects everything else, then the model the wedge grows into.
>
> **Context:** solo founder, real constraints, a ~90‑day validation window (HubSpot, post‑call
> agent), PLG / self‑serve motion, anti‑enterprise ICP. Runway — not the elegance of the model —
> is the binding constraint. Reorganizing the build around the full graph before the wedge is
> validated is building the cathedral while the window closes.

---

## 0. The two rules (read this first)

Everything below is downstream of these. They cost a single afternoon at N=1 and are what keep
"a capability with a real contract" from silently degrading into "a feature."

### Rule 1 — Contract before code

**Write the capability's contract before its implementation, even when there is exactly one
capability.** This is the discipline, not a formality.

The real risk of wedge‑first is **not** retrofit cost — it is *inverse drift*: under 90‑day
pressure, capability #1 ships as a bespoke HubSpot script with the contract bolted on as
decoration, and "capability with a real contract" degrades into "a feature" without anyone
noticing. Writing the contract first is the cheap insurance that prevents it. If this rule slips,
nothing else in this doc holds.

### Rule 2 — Propose‑don't‑mutate (the one non‑negotiable)

**A capability *describes* its effect; a gate *executes* it.** It never writes to the system of
record inside itself.

Of every contract invariant, this is the only one that **composes** — every later capability
inherits the shape — and the only one that carries **blast radius**. It is also the exact safety
property the self‑serve ICP requires, and it is free: the `action‑proposal` pattern already shipped
in chat. **If 90‑day pressure forces honoring exactly one invariant, it is this one.**

---

## 1. Where this sits: a design contract, not a rebuild

We are *moving toward* a **capability compiler** — a world where the output of a conversation is a
typed graph of capabilities over the business's real system of record, and the UI is one rendering
of it. That is the north star and the product category (agents that execute, not chatbots).

But the full graph — opinionated default renderer, read/write contracts with a cost model, a
parity lint, a general connected‑objects framework, an autonomy engine — is a 12–18‑month core
rewrite. **We do not reorganize the build around it before the wedge is validated.**

The commitment is narrower and cheaper: **commit to the graph as a *design contract*, not as a
rebuild.** Every new thing is born as a typed capability (Rules 0). **The graph grows from the
wedge**, one real capability at a time. The general infrastructure emerges when N justifies it —
not before.

The verdict that sets this order: nothing in the graph model becomes *impossible* to retrofit by
starting wedge‑first, **provided capability #1 carries the right contract** (§2). The "rebuild" is
deferrable; only the contract shape of the first capability is load‑bearing.

---

## 2. The capability contract

A capability is a typed, contract‑bearing unit of work. Each carries:

- **Direction:** read, write, or both — declared separately.
- **Shape:** input/output types, tolerant of partial/uncertain typing (adapters, not perfect FKs).
- **Reach:** reads that hit an external system are typed **`remote / async / may-fail`**. The *mark*
  is contract shape and lives in the type from day one; the *cost model* is deferred. By the
  O(N)‑retrofit logic, omitting the mark later is exactly the expensive change — so keep the mark,
  skip the model.
- **Effect:** what it changes, executed only through the gate (Rule 2), never inline.
- **Policy:** who may invoke it; permissions / RLS; autonomy level (safe vs approval‑gated).

### These invariants are not four equals

Honor all of them, but the hierarchy is explicit:

1. **Propose‑don't‑mutate (Rule 2) — the one that composes and carries blast radius.** Non‑negotiable.
2. **Read/write declared separately** — retrofittable hygiene.
3. **Headless, typed boundary** — the capability is callable by a job, a test, and (later) an
   agent; it does **not** live inside a screen/controller mixed with view concerns. Retrofittable
   hygiene; cheap because we already build behind services.
4. **Policy as a property of the capability**, not only route middleware — retrofittable hygiene.

If pressure forces a cut, cut from the bottom, never #1.

---

## 3. Motion & ICP — the hard rule

The motion is PLG / self‑serve; the ICP explicitly rejects the enterprise‑with‑CISO profile.
That makes the most "powerful" feature — an autonomous agent with write access to a production
system of record, wired self‑serve with no onboarding — an **anti‑ICP** risk, not a flagship.

**Hard rule for self‑serve:** connected objects start **read‑first / dry‑run**. Writes to the
system of record happen **only** on capabilities marked `safe` or behind an **explicit gate**. The
blast radius over the customer's own database is not absorbed in PLG.

The **trust ramp** is tied to the motion: start with the agent *proposing* and the human
*approving* (low autonomy, maximum legibility); graduate to autonomous only on capabilities marked
safe. Trust is earned, never assumed — and earning it is also the safest *and* least work at the
wedge, because everything is propose‑and‑approve by default.

---

## 4. Capability #1: the HubSpot post‑call agent (the seeding contract)

Validate the thesis and seed the graph in **one move**. Capability #1:

- **Read** the call — typed `remote / async / may-fail`.
- **Write‑proposal** to the CRM — propose‑don't‑mutate (Rule 2); the human approves.
- **Behavioral verification** — seed a demo call, run it, assert the proposed CRM update is
  correct, show it working, self‑repair if not.

It is a *fixed pipeline* (read → draft → propose), not an agent composing over a partial schema —
which is deliberate (§7). It proves "agents that execute" with the safety the ICP needs, and it is
literally capability #1 of the graph with a real contract. The runtime agent falls on top **when
there are real capabilities underneath** — which is exactly the right order.

---

## 5. The capability graph (the destination the wedge grows into)

As capabilities accumulate, the manifest evolves from `objects / pages / workflows` into a **graph
of capabilities**, with three consumers reading the same contracts:

| Consumer | Role |
|---|---|
| **Generated UI** | A projection of capabilities — the *legibility* surface. |
| **Embedded agent** | A consumer of the same capabilities — the *power* surface. |
| **Verifier** | Proves each capability behaves (§9). |

Graph‑first (as a *design contract*) means the hard problem stops being "generate pretty screens"
and becomes "model the capability correctly." Once the graph exists, the UI derives from it and the
agent gets its toolset from it.

---

## 6. Connected objects — the read path is the unlock

The biggest leap is not the write path (charge, notify, query an ERP) — it is the **read path**:
external systems as the *backing store of the manifest's own objects* ("connected objects"). Without
it you build a CRUD island (an Airtable with buttons); with it, the app becomes a **control surface
over the system of record the business already has.**

The substrate already exists and should unify under one notion: **BYODB** (`byodb_runtime`,
`cloud_providers`) and **integrations** (REST / GraphQL / MCP). For self‑serve, it arrives
**read‑first / dry‑run** (§3). The platform‑grade hard parts: read/write symmetry (one contract),
permission mapping (external auth vs our RLS — the blast radius is real), and partial/drifting
schemas (the `remote/async/may-fail` mark and adapters, §2).

---

## 7. The agent as a runtime primitive

The agent is what separates us from low‑code — but only as a **consumer of the capability graph**,
not a fourth pillar:

- **Free at the toolset level — not at the composition level.** Every capability is automatically
  an affordance, so wiring the agent's tools *is* free. But an agent **composing** capabilities over
  a partial schema with variable latency and cost is precisely where agents blow up in prod (wrong
  tool selection, bad plans, cost blowups). *Toolset free ≠ reliable agent.* The expensive part is
  keeping it from going off the rails while it composes — and wedge‑first **defers that cost**,
  because capability #1 is a fixed pipeline, not a composing agent.
- **Dependent, not parallel.** Without connected objects (read+write) and verification underneath,
  the in‑app agent *is* the chatbot the thesis rejects. Order: capabilities + verification first;
  the composing agent on top.
- **The safety gate is Rule 2.** A propose‑with‑preview mutation, approved by a human (or autonomous
  only on `safe` capabilities), is what turns "an agent that executes" into something deployable.

The recursion that validates the model: *the builder is an agent that grows the graph by
conversation; the apps contain agents that operate on the graph.* Same primitive at build‑time and
run‑time.

---

## 8. The UI as a legibility surface (power vs confusion)

An agent‑first surface is, by default, **both more powerful and more confusing.** Confusion here is
a *design failure*, not an inherent property, and the enumerable graph is what kills it. The two
failure modes are legibility problems:

1. **"What can I ask it?"** — the blank box. Because the graph is enumerable, never present an empty
   prompt: surface what it can do, suggest the next step, constrain to the possible (proposal‑first).
2. **"What just happened?"** — the invisible effect. Every mutation goes through the
   action‑proposal preview, so the effect is seen before it happens and is reversible.

**The UI is not the agent's rival — it is its legibility layer.** The agent *acts*; the UI
*reflects* state, history, and data; both read the same graph. Users point‑and‑click for
precise/bulk/spatial work and talk to the agent for ambiguous/cross‑object/novel work. Confusion
comes from forcing the wrong surface for the task.

**Time‑to‑wow favors wedge‑first.** For PLG the conversion metric is time‑to‑wow, not power, and an
agent‑first surface over an *empty* graph has a slow cold‑start. Wedge‑first sidesteps it: you
hand‑build a polished, single‑purpose UI for the post‑call flow — no empty graph, no generic
renderer. The "secondary UI" concern only bites later, and even then: **secondary = non‑bespoke,
never unfinished** — especially not in the first 90 seconds.

> **Principle:** *an agent is only as trustworthy as what it can do — and what it already did — is
> legible.* The enumerable graph gives us that legibility for free.

---

## 9. Verification: behavioral, not schema‑deep

Validating the manifest against a schema proves it is well‑formed; it does not prove the app
*works*. The loop verifies **behavior**: generate → seed demo data → run the capability → assert
real outcomes ("create an order → a notification fires") → show it working → self‑repair before
handing over. Tests attach to **capabilities**, not screens.

Connected objects raise the stakes: you cannot seed test data into a production ERP, so behavioral
verification *forces* the read‑first / dry‑run / sandbox posture of §3. Verification and connected
objects are two ends of the same hinge.

---

## 10. What this commits us to

- **The contract is the product.** Capability contracts (direction, shape, reach, effect, policy)
  stop being metadata and become where the value lives — three consumers depend on them.
- **The parity invariant.** Anything the UI can do, the agent can do, and vice versa, because both
  are pure consumers. Hard rule: **the UI layer consumes capabilities, it never defines them.**
  Enforced by discipline at small N; a lint when N justifies it.
- **The default‑renderer paradox.** "UI secondary" does **not** mean low‑effort; the *default*
  renderer must be opinionated and polished. Secondary = non‑bespoke, not neglected.
- **Granularity is the central design problem.** Too coarse → the agent can't compose; too fine →
  the graph explodes and both surfaces get noisy. External systems expose awkward "verb sizes."

---

## 11. Build sequencing — wedge‑first

Not "rebuild in inverted order." Grow from the wedge; honor the graph as a design contract,
capability by capability:

1. **Contract for capability #1, before its code** (Rule 1).
2. **Capability #1 — the HubSpot post‑call agent** (§4): read (`remote/async/may-fail`) →
   write‑proposal (Rule 2) → behavioral verification. Hand‑built, polished, single‑purpose UI.
3. **More capabilities**, each born typed and propose‑don't‑mutate, growing the graph from the wedge.
4. **General infrastructure emerges when N justifies it** — default renderer, parity lint,
   capability registry, connected‑objects framework, autonomy engine, verification harness. None of
   it is load‑bearing for the wedge; all of it retrofits cleanly because the contracts were right
   from #1.
5. **The composing runtime agent** falls out once there are real capabilities underneath.

---

## 12. Why this is defensible

Canvas‑first tools (Retool, Bubble) give you a canvas. We give a **capability graph over the
customer's real system of record**, with two surfaces (human and agentic) and a behavioral
verification loop that backs them. They were born UI‑first and cannot retrofit this without
rewriting their core. The moat is the contract + the connected‑object integration + the
verification + the dual surface — not any single screen.

---

## 13. Open questions

- **Granularity:** the right "verb size" for a capability, kept stable as connected objects expose
  messy external APIs.
- **Write‑back semantics:** transactions/conflicts when a capability spans internal records *and* an
  external system of record.
- **Autonomy policy:** how a capability earns the `safe` / autonomous mark, and who decides.
- **Primary surface drift:** for which app classes the agent becomes the primary surface and the UI
  the fallback — and how an app evolves along that axis without a rebuild.
