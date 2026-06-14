# Capability Contract 0001 — HubSpot Post‑Call Agent

> **Status:** Contract (pre‑implementation). Written **before** any code, per Rule 1 of the
> [App Builder vision](../sapiensly-app-builder-vision.md). This is capability #0001 — the seed of
> the capability graph — so its structure is also the **template** every future capability contract
> must follow (see §11).
>
> **Honors:** Rule 1 (contract before code) · Rule 2 (propose‑don't‑mutate) · the self‑serve hard
> rule (read‑first / dry‑run; writes to the system of record only `safe` or behind an explicit gate).

---

## 0. Intent

When a sales call ends, read the call, draft the CRM update it implies, and **propose** it to the
user for one‑click approval — never writing to HubSpot on its own. This validates the thesis
("agents that execute, not chatbots") with the safety the self‑serve ICP requires, and seeds the
graph with a first real, typed, verified capability.

The unit is a **fixed pipeline**, not a composing agent:

```
read FetchCallContext  →  compute DraftCrmUpdate  →  [human approves]  →  gated ApplyCrmUpdate
        (remote)              (pure + LLM)               (the gate)          (write, not safe)
```

Decomposing into three typed sub‑capabilities is deliberate: it keeps the *read* separable, the
*proposal* free of side effects, and the *write* isolated behind the gate.

---

## 1. Identity

| Field | Value |
|---|---|
| `id` | `cap_0001_hubspot_post_call_agent` |
| `version` | `0.1` (contract) |
| `surface` | seeds: runtime agent (later) + a hand‑built post‑call UI (now) |
| `backing system` | HubSpot (connected object via an `Integration`, OAuth) |
| `tenant scope` | per‑tenant; RLS‑scoped like all tenant data |
| `autonomy` | **approval‑gated** (not `safe`) for v1 |

---

## 2. Shape — the types

Types are **partial‑tolerant**: external data is rarely complete or stable, so most read fields are
optional and consumers must degrade gracefully (§7). Pseudo‑types below are contract, not code.

### 2.1 Read output — `CallContext`

```
CallContext {
  call_id: string                         // HubSpot engagement/call id
  occurred_at?: datetime
  direction?: 'inbound' | 'outbound'
  duration_seconds?: number
  participants?: { hubspot_owner_id?: string; contact_id?: string; phone?: string }[]
  transcript?: string                     // may be absent (not all calls are transcribed)
  recording_url?: string
  associations?: {                        // existing CRM objects this call is linked to
    contact_id?: string
    company_id?: string
    deal_id?: string
  }
  source_fetched_at: datetime             // snapshot timestamp (read is a snapshot, not live)
}
```

### 2.2 Proposal output — `CrmUpdateProposal`

The effect **descriptor**. It is data, not an action. Conforms to the generic effect shape so the
gate (§3.3) is capability‑agnostic.

```
CrmUpdateProposal {
  proposal_id: string
  capability_id: 'cap_0001_hubspot_post_call_agent'
  target: { object_type: 'contact' | 'deal' | 'note' | 'task'; object_id?: string }  // object_id absent ⇒ create
  operation: 'create' | 'update'
  changes: { field: string; from?: any; to: any }[]    // before/after, partial-tolerant
  rationale: string                                     // ≤200 chars, in the call's language
  confidence: number                                    // 0..1 — drives the gate (§7)
  evidence?: { quote: string; offset_seconds?: number }[]  // grounding from the transcript
  produced_at: datetime
}
```

### 2.3 Execution result — `CrmUpdateResult`

```
CrmUpdateResult {
  proposal_id: string
  status: 'applied' | 'rejected' | 'failed'
  hubspot_object_id?: string
  applied_at?: datetime
  error?: string
}
```

---

## 3. Direction & effect

Declared **separately**, per the contract hierarchy. Propose‑don't‑mutate is the non‑negotiable.

### 3.1 `FetchCallContext` — read

- **Direction:** read.
- **In:** `{ call_id, tenant }` → **Out:** `CallContext`.
- **Reach:** `remote / async / may-fail` (§4). Snapshot, not live‑linked.
- **No side effects.**

### 3.2 `DraftCrmUpdate` — propose (no side effects)

- **Direction:** read‑only compute (LLM over `CallContext`, plus a read of the linked CRM object's
  current field values for the `from` side of `changes`).
- **In:** `{ CallContext, tenant }` → **Out:** `CrmUpdateProposal`.
- **Writes nothing.** Producing a proposal is **not** an effect. This is Rule 2.

### 3.3 `ApplyCrmUpdate` — write (gated)

- **Direction:** write to the system of record.
- **In:** `{ CrmUpdateProposal, approver }` → **Out:** `CrmUpdateResult`.
- **Precondition:** an explicit approval token from the gate. Never invoked inline by §3.1/§3.2.
- **Autonomy:** approval‑gated for v1; eligible to be marked `safe` only per §5.
- **Idempotent:** keyed by `proposal_id` so a retry never double‑writes (§4).

> The gate is generic and reusable — the same propose→preview→approve→execute shape already shipped
> in chat (`action‑proposal` / `ActionExecutor`). Capability #0001 supplies the descriptor and the
> executor; the gate itself is not capability‑specific.

---

## 4. Reach — HubSpot (remote / async / may‑fail)

The `remote/async/may-fail` mark is **contract shape from day one** (the cost *model* is deferred).

- **Auth:** OAuth via a tenant `Integration`; required scopes declared up‑front
  (`crm.objects.contacts.read/write`, `crm.objects.deals.read/write`, `crm.objects.notes`,
  engagements/calls read). Missing scope ⇒ typed `permission_error`, surfaced, never a silent skip.
- **Timeouts:** per external call (default 5s read); on timeout the read returns a partial
  `CallContext` with the missing source noted, it does not abort the pipeline.
- **Rate limits:** respect HubSpot limits; backoff on 429; degrade rather than fail the turn.
- **Idempotency:** writes carry the `proposal_id` as an idempotency key.
- **Consistency:** reads are snapshots stamped with `source_fetched_at`; proposals show the user the
  snapshot they were computed from.

---

## 5. Policy

- **Invoker:** the tenant user who connected the HubSpot integration (or an org member with
  permission to that integration). Tenant/RLS scoped like all tenant data.
- **Permission mapping:** the app's authorization gates *invocation*; HubSpot OAuth scopes gate the
  *external* read/write. Both must pass; mismatches surface explicitly.
- **Autonomy ladder (tied to the motion):**
  - **v1 — approval‑gated only.** Read‑first / dry‑run; every write is propose→approve. This is the
    self‑serve hard rule; the blast radius on the customer's CRM is not absorbed in PLG.
  - **Later — `safe` graduation** is earned per object_type + operation + confidence threshold, opt‑in
    per tenant, reversible. Out of scope for v1 (§8).

---

## 6. Behavioral verification (acceptance)

Verification is **behavioral, not schema‑deep**. The capability is not "done" until these pass
against seeded data; failures trigger self‑repair before anything ships.

**Setup:** seed a demo `CallContext` fixture (an outbound call with a transcript that clearly
implies a deal‑stage change and a follow‑up task), plus a fake/sandbox HubSpot adapter so no real
CRM is touched (the read‑first / dry‑run posture is what makes this possible).

**Assertions:**

1. **Read** — `FetchCallContext` returns a `CallContext` with `call_id`, `source_fetched_at`, and the
   transcript present; a missing transcript yields a partial result, not an error.
2. **Propose** — `DraftCrmUpdate` returns a `CrmUpdateProposal` whose `target`/`operation` match the
   demo intent (e.g. update deal stage), with `changes` carrying correct `from`/`to`, a non‑empty
   `rationale`, `confidence ∈ [0,1]`, and at least one `evidence` quote drawn from the transcript.
3. **No side effect on propose** — after read+propose, the sandbox CRM is **unchanged** (proves
   Rule 2).
4. **Gate** — `ApplyCrmUpdate` refuses to run without an approval token; with one, it writes once and
   returns `status:'applied'` with a `hubspot_object_id`.
5. **Idempotency** — applying the same `proposal_id` twice writes once.
6. **Degradation** — with the transcript removed, the pipeline still produces a lower‑`confidence`
   proposal or an explicit "insufficient signal" outcome — never a crash, never a silent write.
7. **Show it working** — the post‑call UI renders the proposal (target, before/after, rationale,
   evidence) and the applied result inline.

---

## 7. Failure modes & degradation (may‑fail handling)

| Condition | Behavior |
|---|---|
| HubSpot read times out / 5xx | partial `CallContext`, missing source noted; pipeline continues |
| No transcript | proceed on metadata + associations; lower `confidence` |
| No matching CRM object | propose a `create`, or a `note` if ambiguous; never guess an `object_id` |
| `confidence` below threshold | still propose, flagged low‑confidence; **never** auto‑applied |
| Missing OAuth scope | typed `permission_error`, surfaced with the scope needed |
| Write rejected by HubSpot | `CrmUpdateResult.status:'failed'` with `error`; proposal stays re‑proposable |

---

## 8. Non‑goals (v1)

- No autonomous / un‑gated writes (no `safe` graduation yet).
- No composing agent across multiple calls or capabilities — fixed pipeline only.
- No object types beyond contact / deal / note / task.
- No cost model (only the `remote/async/may-fail` mark).
- No general connected‑objects framework — HubSpot‑specific adapter; generalize when N justifies it.

---

## 9. Data & PII

- The transcript and `CallContext` are a **snapshot**, stored only as needed to produce and audit the
  proposal; no PII beyond what already exists in the connected integration.
- `evidence` quotes are minimal excerpts for grounding, not the full transcript.
- Retention and redaction follow the platform's tenant data rules (RLS‑scoped).

---

## 10. Observability / persisted records

- Persist each `CrmUpdateProposal` and its `CrmUpdateResult` (proposal → decision → outcome), so the
  thread of read → propose → approve → apply is fully auditable.
- Log the read snapshot timestamp, confidence, approver, and idempotency key.

---

## 11. This is the template

Every future capability contract carries the same spine — this is what "contract before code" means
in practice:

1. **Identity** — id, version, backing system, tenant scope, autonomy level.
2. **Shape** — typed, partial‑tolerant I/O.
3. **Direction & effect** — read / write declared separately; **propose‑don't‑mutate** (the only
   invariant that composes / carries blast radius; honor it even if everything else is cut).
4. **Reach** — the `remote/async/may-fail` mark on external access (mark always; cost model later).
5. **Policy** — invoker, permission mapping, autonomy ladder tied to the motion.
6. **Behavioral verification** — seeded data, concrete assertions, self‑repair; tests bind to the
   capability, not a screen.
7. **Failure modes** — explicit degradation, never a silent write or a crash.
8. **Non‑goals** — the scope cuts that keep the wedge shippable.

If a future capability cannot fill section 3 with a clean read/write split and a propose‑don't‑mutate
write, it is not yet a capability — it is a script with a contract bolted on. Stop and reshape it.
