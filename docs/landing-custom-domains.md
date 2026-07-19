# Landing Custom Domains

How a tenant serves a published landing on their **own** hostname
(`landing.acme.com`) — the "own the surface, rent the hard infra" half of the
Landing Builder: Sapiensly owns routing and rendering; Cloudflare for SaaS
(when configured) rents us TLS issuance/renewal, WAF and DDoS at the edge.

## The user flow

1. **Connect** — `manage_landing_domain {app_slug, action: "connect", hostname:
   "landing.acme.com"}` (MCP; confirm-carded in chat). Registers the hostname
   and returns the DNS instruction: *add a CNAME `landing.acme.com` → the
   platform's cname target*.
2. **Customer updates DNS** at their registrar.
3. **Verify** — `action: "verify"` re-checks DNS (and Cloudflare status when
   configured) and flips the domain to `active` when everything lines up. The
   response carries per-check detail with actionable reasons ("CNAME points at
   X, expected Y"), so a failed verify tells the customer exactly what to fix.
4. **Serve** — `https://landing.acme.com/` renders the published landing at its
   root. The lead form keeps working because the `/l/{slug}/lead` endpoints are
   host-agnostic (same origin on the custom domain).
5. **Disconnect** — `action: "disconnect"` removes the row (and the Cloudflare
   hostname); the domain stops serving immediately.

`action: "status"` reports the current state + the cname target at any time.

## Architecture

| Piece | Role |
|---|---|
| `custom_domains` table (platform schema) | Control-plane row per hostname: `hostname` (globally unique — the routing key), `app_id` (cascade on app delete), `status` (`pending` → `active`, `failed` kept for observability), `cf_hostname_id`, `verified_at`. |
| `App\Models\CustomDomain` | Platform-connection model, `dom_` ulid prefix, `active()` scope. |
| `App\Services\Landing\CustomDomainService` | The lifecycle: `connect` (hostname validation, platform-host/duplicate/non-landing rejection, CF registration), `verify` (DNS + CF checks → activate), `disconnect`, and `appForHost` (the Host-header lookup the serving path uses). |
| `App\Services\Landing\DnsResolver` | Thin `dns_get_record` wrapper so verification is testable (tests bind a fake). |
| `App\Services\Landing\CloudflareCustomHostnames` | Cloudflare for SaaS custom-hostnames API client (create/status/delete). Only runs when configured. |
| Root route branch (`routes/web.php`) | `GET /` resolves the Host header: an **active** custom domain renders the published landing (owner tenant scope bound, same `PublicLandingController` as `/l/{slug}`); platform hosts keep the login/chat redirect. |
| `manage_landing_domain` (MCP, `apps:build`) | connect / verify / status / disconnect. Listed in `PlatformToolsFactory::CONFIRM_REQUIRED` — outward-facing infra always goes through the user-confirmation card in chat. |

## Serving semantics (the important edge cases)

- Only `status = active` domains serve. A `pending` domain's host behaves like
  any unknown host (platform redirect).
- An active domain whose landing was **unpublished** (or is no longer a
  landing) returns **404** — a visitor on the customer's domain is never
  bounced to the Sapiensly login.
- Rendering is identical to `/l/{slug}`: chrome-less, presentational-blocks
  allowlist, eager props, SEO head, owner tenant scope for RLS.

## Configuration

```env
# Optional — without these the flow still works on the DNS check alone
# (local/dev, or a deployment fronted some other way).
CLOUDFLARE_SAAS_API_TOKEN=   # API token with SSL and Certificates edit on the zone
CLOUDFLARE_SAAS_ZONE_ID=     # the zone that hosts the fallback origin
CLOUDFLARE_SAAS_CNAME_TARGET=landings.sapiensly.com  # what customers CNAME to
```

With no `CLOUDFLARE_SAAS_CNAME_TARGET`, the target defaults to the host of
`APP_URL`.

### One-time production setup (ops)

1. In the Cloudflare zone, enable **Cloudflare for SaaS** and set the
   **fallback origin** to the host that serves this app.
2. Create the `CLOUDFLARE_SAAS_CNAME_TARGET` DNS record pointing at that
   origin (proxied).
3. Issue the API token (Zone → SSL and Certificates → Edit) and set the env
   vars.
4. Ensure the web server / proxy forwards arbitrary Host headers to the app
   (the app routes by Host; it must actually receive it).

## Verification model

- **DNS check** (always): the hostname's CNAME must resolve to the cname
  target. Wrong/missing targets produce actionable check messages.
- **Cloudflare check** (when configured): the custom hostname must be
  `active` with `ssl.status = active` (certificate issued).
- Both must pass to activate. Re-running `verify` is idempotent; `verified_at`
  is set once.

## Current limitations

- **CNAME-only**: apex domains (`acme.com` without a subdomain) need A/ALIAS
  support — not implemented yet; point a subdomain (`www.`, `landing.`).
- **One domain per app** in practice (the tool operates on the app's latest
  domain row); the table supports more when multi-domain lands.
- **No builder UI yet** — connect/verify run through MCP/chat; the builder
  header UI (like Publicar) is a follow-up.

## Tests

- `tests/Feature/Landing/CustomDomainTest.php` — Host-header serving (active /
  pending / unpublished-404 / platform untouched), the connect→verify→active
  lifecycle with a fake DNS resolver, hostname validation, and the Cloudflare
  register/teardown path via `Http::fake`.
- `tests/Feature/Mcp/ManageLandingDomainToolTest.php` — the MCP tool end to
  end (connect → verify → disconnect, status without a domain errors cleanly).
