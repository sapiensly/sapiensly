# Builder Power Contract — Create an Integration in Conversation

> **Status:** Contract (pre-implementation). Written before code (Rule 1).
> **Altitude:** this is a *power of the App Builder*, not an app. We are giving the
> builder's LLM the ability to author and verify an integration during a
> conversation. The integration is a **user output**, produced by talking to the
> builder — never hand-coded by us, never provider-specific. "HubSpot" is just
> what the user happens to type.
>
> Power #1 of the connected-objects design
> ([design](./app-builder-connected-objects-design.md)). The shape here — builder
> tools + propose→approve + verify, reusing existing services, provider-agnostic —
> is the template for every future builder power.

---

## 0. Intent

Give the App Builder the power to, **in conversation**, stand up a working
connection to any external HTTP API — OAuth2-discoverable *or* api-key/bearer —
and prove it works. The output is a **verified, per-tenant `Integration`** (the
existing platform resource) that later powers connected objects.

Provider-agnostic by construction: there is **no HubSpot code, no per-provider
connector**. The same power produces a Stripe, a HubSpot, or a niche-ERP
connection from the same conversation.

---

## 1. Altitude guard (what this is NOT)

- ❌ Not a `HubSpotConnector`, not any provider class.
- ❌ Not an app, a capability vertical, or a bespoke table.
- ✅ A capability of the builder's LLM, expressed as builder tools, reusing
  `OAuth2DiscoveryService`, `IntegrationService`, and `IntegrationRequestExecutor`.

If implementing this requires writing provider-specific code, the design is wrong —
stop and reshape it.

---

## 2. The new builder tools (LLM-callable)

Added alongside the builder's existing read/inspect/propose tools. Each: inputs →
output, and side-effect class.

- **`discover_integration(url)`** → wraps `OAuth2DiscoveryService::autoConfigure`.
  Returns a **draft** config (base_url, auth_type, authorize/token URLs, scopes,
  whether a client was dynamically registered). **Read-only** (no persistence).
  `remote / may-fail`: a non-discoverable URL returns "not discoverable" so the
  builder falls back to manual fields.
- **`propose_integration(spec)`** → proposes creating an `Integration` (name,
  base_url, auth_type, non-secret config). **Not created until the user approves**,
  surfaced like every other builder proposal. On approval → `IntegrationService::create`
  (per-tenant).
- **`request_secret(field)`** → asks the user for a credential (api key / bearer /
  client secret) through a **secure input in the builder UI**. The value is
  **never typed into plain chat and never passed to the LLM**; it is stored
  encrypted in the integration's `auth_config` on approval.
- **`test_connection()`** → fires **one real request** via
  `IntegrationRequestExecutor` and returns success/failure plus a small response
  sample. This is the verification gate.

---

## 3. The flow — discover → propose → authorize → verify

All in the conversation, on the builder's existing `propose → approve` loop:

1. **Intent** — the user says "connect to X / I need data from X".
2. **Discover** — builder calls `discover_integration`. If not OAuth-discoverable,
   it asks for base_url + auth kind, **proposal-first** (recommends the likely one).
3. **Propose** — builder proposes the integration; the user approves →
   `IntegrationService::create`.
4. **Authorize** —
   - **OAuth2:** builder surfaces a "Connect X" action; the user completes the
     existing consent flow; control returns to the conversation. **The consent
     click is the human gate.**
   - **api-key / bearer:** builder uses `request_secret`; stored encrypted.
5. **Verify** — builder calls `test_connection`. Only on success is the connection
   "ready". On failure it self-corrects or re-asks — never reports a false "connected".

Outcome: "conéctate a X" in chat → a verified, per-tenant `Integration`, with
discovery + a real test, not a pasted JSON.

---

## 4. Auth types in scope

- **OAuth2 authorization-code** (with discovery + dynamic client registration).
- **api-key / bearer** (secure conversational capture).

OAuth tokens for builder-created integrations are stored **org-level** (on the
`Integration`, auto-refreshed), so connected objects can serve data later without a
user session present.

---

## 5. Multitenancy & security

- The `Integration` is a **platform resource, per-tenant** (`HasVisibility`),
  `auth_config` encrypted. Visibility follows the creator's account context.
- **Secrets never reach the LLM or the chat transcript** — captured through a
  secure field, stored encrypted.
- Every probe/test call goes through the **SSRF guard**.
- Connection resolution is **tenant-scoped** — the builder can only create/see the
  tenant's own integrations.

---

## 6. What it authors (boundary)

- ✅ A verified `Integration` (platform, per-tenant).
- ❌ It does **not** touch the app manifest or create connected objects — that is
  builder power #2. ❌ It does not make the app *use* the integration yet — power #2/#3.

---

## 7. Behavioral acceptance

In a real conversation against faked HTTP:

1. "Connect to `<some OAuth2 API>`" → discovery returns a draft → proposal →
   (consent) → `test_connection` passes → a per-tenant `Integration` exists,
   `auth_config` encrypted, status ready.
2. "Connect to `<some api-key API>`" → builder requests the secret via the secure
   field (the secret never appears in the transcript / LLM input) → proposal →
   `test_connection` passes → integration ready.
3. A non-discoverable URL → builder falls back to manual base_url + auth, no crash.
4. `test_connection` failure → integration stays **unverified**, surfaced,
   re-proposable; never a false "connected".
5. Nothing in the implementation is provider-specific (no "hubspot" string in code
   paths).
6. The integration is tenant-scoped — another tenant cannot see or use it.

---

## 8. Non-goals (this power)

- Connected objects (manifest `source: connected`) — power #2.
- The app actually using the integration at runtime — power #2/#3.
- Any provider-specific connector or app.
- Per-user OAuth tokens (org-level for now; per-user is a later option if a
  capability needs it).

---

## 9. Why this is the right first builder power

It is the smallest power that makes the builder able to "create the required
integrations in the conversation" — the literal goal — and it produces the
per-tenant connection that connected objects (power #2) will reference. It reuses
the entire existing OAuth/integration substrate, so we add **builder authoring**,
not a new integration stack. And it is provider-agnostic, so it scales to every API
a user might name — composition, not bespoke code.
