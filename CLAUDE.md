# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What is Sapiensly?

Sapiensly is a B2B SaaS platform for **Autonomous Agent Orchestration**. It transforms passive chatbots into active agents that execute tasks.

**MVP Focus: Autonomous Customer Service** - Deploy a "digital squad" that resolves Level 1 and 2 support tickets without human intervention.

### The Agent Triad
1. **Triage Agent**: Classifies intent, urgency, and sentiment
2. **Knowledge Agent (RAG)**: Searches company documentation (manuals, FAQs) with strict tenant isolation
3. **Action Agent**: Executes real-world operations (check orders, process refunds, update records) via controlled tools

### Core Architecture Patterns

**Modern Monolith**: Prioritizes development speed and enterprise robustness.

**Central Orchestrator**: No linear scripts. The backend dynamically decides which agent/tool to invoke based on conversation state.

**Tooling Layer**: Laravel packages are encapsulated as AI Tools. Agents interact with the real world through controlled internal APIs—never touching the database directly.

**Tenant-Aware RAG**: Vector search (pgvector) runs on the RLS-protected `tenant` schema, so chunk queries are automatically scoped to the current organization/user by Postgres Row-Level Security. Agents cannot access data from other tenants. See "Database & Multi-Tenancy" below.

**Streaming Feedback**: AI inference is decoupled via queues. Each agent step streams to the frontend via WebSockets, showing users the bot "thinking" rather than just waiting.

### Key Technologies
- **AI Integration**: `laravel/ai` (the official Laravel AI SDK — agents, tool calling, streaming, structured output; it ships its own multi-provider driver layer)
- **Hybrid Database**: PostgreSQL + pgvector for relational data and embeddings
- **Multi-tenancy**: PostgreSQL Row-Level Security + a 3-role / 2-schema split (see "Database & Multi-Tenancy"). Auth is Fortify + spatie/laravel-permission (teams = `organization_id`)
- **Async Processing**: Redis + Laravel Horizon for AI queues
- **Real-time**: Laravel Reverb + Echo for WebSocket token streaming

## Build & Development Commands

```bash
# Development (starts server, queue, logs, and vite concurrently)
composer dev

# Development with SSR
composer dev:ssr

# Run tests (clears config then runs pest)
composer test

# Run a single test file
php artisan test tests/Feature/DashboardTest.php

# Run a specific test by name
php artisan test --filter=test_dashboard_is_displayed

# PHP code formatting (Laravel Pint)
./vendor/bin/pint

# Frontend linting (ESLint with auto-fix)
npm run lint

# Frontend formatting (Prettier)
npm run format

# Check frontend formatting
npm run format:check

# Build frontend assets
npm run build
```

## Architecture Overview

This is a Laravel 12 + Inertia.js + Vue 3 application. Auth is Laravel Fortify (login, registration, 2FA, password reset) plus optional Google social login and per-organization OIDC SSO; authorization is spatie/laravel-permission with the teams feature keyed on `organization_id`.

### Backend Structure
- **Routes**: Split across `routes/web.php`, `routes/auth.php`, `routes/settings.php`
- **Authentication**: Laravel Fortify + spatie/laravel-permission (teams = `organization_id`); `SetPermissionsTeam` middleware sets the team per request
- **Controllers**: Located in `app/Http/Controllers/`, with settings controllers in a `Settings/` subdirectory

## Landing Builder

Landings are apps with `settings.surface="landing"` (auto-tagged `kind=landing`), rendered chrome-less/full-bleed and — once published — public at `/l/{public_slug}` (kebab-case, globally unique; `LandingPublisher`) or at a custom domain (`CustomDomainService`, CNAME + optional Cloudflare-for-SaaS). They are **bespoke-designed by construction**: pages are `html` blocks styled via `settings.custom_css` (60k budget, compiled/scoped by `ScopedAppCss`; `@import` forbidden) plus `data-sp-*` motion hooks hydrated by the runtime. The conversion loop is a leads object (use the real `email`/`url`/`phone` field types) + a `lead_form` block + a `record.created` workflow; the public form posts with honeypot/Turnstile/throttle built in.

**Deterministic rails** (prompt rules alone proved insufficient — each rail exists because a live build violated it):
- `ManifestValidator` rejects the generic marketing blocks (`hero`, `feature_grid`, `cta`, `testimonials`, `pricing`, `faq`, `stat_band`) anywhere on a landing surface (`generic_block_on_landing`).
- `ScaffoldAppTool` refuses landing-intent requests (`App\Support\Landing\LandingIntent`; escape hatch `confirm_not_landing`).
- The lead form must belong to the design: an empty `<div data-sp-slot="lead_form"></div>` in the html places it (BlockLeadForm moves itself in on mount) and `.sp-lead-form` must be styled — both enforced by the design gate's floor.

**The design gate** (`LandingDesignCritic`, exposed as `critique_landing_design` in the builder and over MCP) is mandatory before finishing: a deterministic floor (bespoke css, display type scale, motion, the lead-form rules) + a demanding director model pass — the director also enforces **brand adherence** (build the palette around the org Brandbook accent, show the brand logo) whenever the Brandbook has content, and is silent when it's empty (`LandingDesignCritic::brandFacts` keys off the raw-set values, never the platform-default fallback), with a convergence policy (score ≥85 ships; round 3 demotes leftovers to polish). The director call runs with a 120s timeout (frontier directors deliberate 45–100s) and its outcome is explicit in the result (`director: 'ok' | 'failed' | 'skipped'`): a *failed* pass is retryable and blocks a floor-only ship before the round cap — it never silently counts as approval (a 45s timeout did exactly that in a live build). The gate is also deterministically enforced: the first ship:true stamps `builder_conversations.landing_shipped_at`, and an applied landing turn on an unshipped conversation gets a platform-queued gate turn (`BuilderAiService::continueForLandingGate`, bounded by the job's `gateRemaining`) — a model that "finishes" without a verdict doesn't get to. The director sees **real pixels** from three sources, best available first: `DraftPreviewShot` asks the open builder tab to render the current draft off-screen and post a JPEG back (cache rendezvous via `TenantCache`, no tenant storage needed); `LatestPreviewShot` reads the last upload; and `HeadlessLandingShot` renders server-side via Browsershot on the signed `landing.render` route (works with NO browser attached — an external/headless caller now judges pixels too, `judged_pixels:true`, instead of degrading to text-only). The same headless renderer backs the `render_landing` MCP tool, which returns a full-page screenshot so an external agent can SEE what it authored via `propose_change` (the design escape hatch the model otherwise builds blind). `judged_pixels` reports `'draft' | 'applied' | false`.

**Model routing**: two optional modules in `AiDefaults` (admin AI > Defaults) split the CONSTRUCTOR from the DIRECTOR. `landing_builder` switches the builder to a dedicated model for landing work — from turn ONE via `BuilderAiService::moduleFor()` (app tagged landing, or the request matches `LandingIntent`); the UI picker only sends an explicit `model` override when the user actually picked one. `landing_director` is the gate's judge: `LandingDesignCritic::directorCandidates()` resolves explicit → director primary → director fallback (the retry when the primary pass fails) → inherits the landing_builder → builder chain, capped at 2 attempts per gate call. Every director pass bills to the usage ledger (module `landing_director`, tagged app+conversation via `forSubject`), surfacing as the "Landing Director" service line in `get_build_cost`.

**Chatbot on a landing**: a landing binds ONE of the organization's chatbots by id (`settings.chatbot = {id, position?, greeting?}`) and it renders as a floating bubble. The binding is by reference — the widget's bearer token is never in the manifest (which is versioned, diffed and read by the builder model); `LandingChatbot` resolves it at render. Two rails in `ManifestValidator`: landing surface only, and the bot must belong to the app's owner (re-checked at render, because the widget API binds the CHATBOT's tenant scope). `apps.chatbot_id` is denormalized from the manifest in `AppManifestService::createVersion` so "which published landings serve this bot?" is an indexed read — the question `ChatbotLandingOrigins` asks on every widget request to widen `allowed_origins` with the landing's own origins. That widening only ever applies to a list the tenant ALREADY restricted: an empty `allowed_origins` means "allow all" and must keep meaning it, or publishing a landing would silently lock out every external embed of that bot. The published page mounts the same `widget.js` bundle external sites embed (one streaming client, not two), passing per-page appearance through `sapiensly('init', token, {appearance})`; the builder preview renders a static inert bubble instead (no session, no tokens billed), and the draft-shot pane renders neither, so the design director never judges it. Tests in `tests/Feature/Landing/LandingChatbotTest.php` and `ChatbotLandingOriginsTest.php`.

**Multilingual landings**: ONE landing serves every language — never two apps (two manifests drift the first time someone edits a button). `settings.languages` lists them, first = the default (the language each block's `content` is written in); each html block carries `content_i18n: {lang: markup}` with the SAME structure and only the words changed (shared custom_css + position-addressed fine-tune gestures depend on it), and `settings.seo_i18n` translates the head. The page ships no JS, so it cannot read `navigator.language`: `LandingLanguages` decides SERVER-side in `PublicLandingController` — explicit `?lang=` → the visitor's remembered cookie → `Accept-Language` (q-weighted, with region→base fallback) → default. `LandingRuntimeProps::build` resolves the variant INTO `content` and drops the map, so one language reaches the browser; untranslated blocks fall back. `<html lang>` comes from `app()->setLocale`, `hreflang`+`x-default` alternates are built server-side from the request URL (custom domains included) so SSR ships them, and `VaryOnNegotiatedLanguage` adds `Accept-Language, Cookie` to `Vary` — it is PREPENDED to the `web` group because Inertia's middleware *replaces* `Vary` on the way out, so a header set in the controller would not survive. Variants pass the same `LandingHtmlSanitizer`. Four save-time rails reject translations that could never be served (undeclared language, a variant keyed with the default, no `settings.languages`, non-landing surface). EVERY surface that renders a landing resolves the language through `LandingLanguages::apply` — the public page, the headless shot AND the authenticated runtime `/r/{slug}` (the URL the platform hands you at create time, and the one that silently ignored `?lang=` at first). Reviewing a translation before it ships is first-class: the builder preview carries a per-language picker (the author chooses; the public page negotiates), and `landing.render` / the `render_landing` MCP tool take `lang`/`language` — without that you only ever review the default and a translation ships unseen. Tests: `tests/Feature/Landing/MultilingualLandingTest.php`.

**Typography**: seven self-hosted OFL families (Fraunces, Instrument Serif, Bricolage Grotesque, Archivo variable-width, IBM Plex Mono, plus the poster voices Alfa Slab One and Anton) declared in `resources/css/landing-fonts.css`, referenced by family name in custom_css. Beyond the catalog, `settings.fonts` loads ANY Google Fonts family (max 4, `"Family:400,700,400i"` specs, schema-pattern validated — the spec becomes a URL so the pattern is the security) from the privacy-friendly mirror fonts.bunny.net, rendered on the runtime page (`<Head>` links, SSR-safe), the builder preview and the draft-shot pane (`resources/js/runtime/fonts.ts`); `@import` in custom_css stays forbidden. The playbook matches face to intent (poster brief → poster face).

**Fine-tune (manual click-and-edit)**: once built, the Builder flips to a "Fine-tune" mode for landings (`panelMode` in `apps/Builder.vue`) — reorder/duplicate/delete sections (`blocks/move|duplicate|delete`), edit text in place (`blocks/content`, surgical swap on the STORED string), and style per element (`blocks/style` → `FineTuneStyles`): each styled element gets a lazy `data-sp-edit-id` anchor and an override rule in a FENCED managed region of custom_css. Every gesture is a versioned patch, so AI ⇄ manual is one shared manifest. The round-trip is a deterministic rail: `AppManifestService::createVersion` runs `FineTuneStyles::preserve` on EVERY save — the managed region survives an AI turn that drops it and is always kept LAST so manual overrides out-cascade AI-appended rules. `FineTuneStyles::sanitize` (property whitelist + strict value validation) is the trust boundary for style values.

**Working notes**: **custom_css** is written/revised in chunks with the `{op:"append", path, value}` extension in `ManifestPatch` — never resend a huge `replace`. A block's html **`content` is the opposite: write it in ONE op**. Every save runs `LandingHtmlSanitizer`, which parses and REPAIRS the string, so a partial first chunk comes back with its open tags closed and every later chunk lands *outside* the element it belonged to — cutting on element boundaries doesn't help, the ancestors are still open at the cut. Observed live: half a marquee ended up outside its own track, rendering as a stray second row while every patch reported success. On the Anthropic driver, builder turns cache the CONVERSATION, not just the system prompt: `CachingAnthropicGateway` (bound over the stock gateway in `AppServiceProvider`) applies a moving `cache_control` breakpoint to the last message when `BuilderAgent` opts in — without it every round trip re-bills the whole history at full input rate (~60% of a live Fable build's bill). The landing surface wrapper clips horizontal overflow (`overflow-x-clip` in `runtime/Page.vue`). Tests live in `tests/Feature/Landing/`, `tests/Unit/Services/Landing/`, `tests/Unit/Support/Landing/`; the authoring playbook the builder reads is the `landings` topic in `FrameworkReferenceTool` + rule 1d-land in `BuilderAiService` — keep those in sync with any behavior change here.

## App Builder

The other builder surface: apps (`kind != landing`) built conversationally in `BuilderAiService`. A turn resolves its model from the `builder` module, runs a tool loop, and accumulates `propose_change` ops onto ONE running draft that is applied at turn end as a new version. Long work runs as a plan chain (`BuildPlan`, `target_plan_steps`), each step a queued auto-turn.

The section below is mostly a list of rails, and every one of them exists because a live build walked past the prompt-only version of the same rule. Prefer adding a rail over adding a sentence to a prompt.

**The closing critic** (`App\Services\Apps\BuildCritic`) reads the finished app against the request that produced it and answers in two directions: `missing` (asked for, absent) and `unrequested` (present, nobody asked, invented subject matter — ordinary CRUD scaffolding does not count). Both directions come from the same measured failure: a builder that runs out of road does not stop, it narrates success. It returns `critic: 'ok' | 'failed' | 'skipped'` so a caller can tell "nothing to report" from "nobody looked" — the distinction the landing gate learned the hard way, where a timed-out director silently counted as approval. Model routing is the `build_critic` module in `AiDefaults` (admin AI > Defaults, the same view as the landing director); `criticCandidates()` resolves explicit → build_critic primary → fallback → the builder's own chain, capped at 2 attempts. Passes bill as the "Build Critic" service line in `get_build_cost`.

**It runs server-side, once per applied turn.** `BuilderAiService::continueForBuildCritic` calls it directly; there is no `critique_build` tool and the model must never get one. As a tool it was two unbounded knobs at once — the model's discretion inside a turn plus the rail queueing more turns — and one build spent SIX passes, more than half its bill, arguing with a critic reading the wrong manifest. Server-side the count is one per applied turn by construction. The queued follow-up carries the FINDINGS themselves, not an instruction to go and look. Bounded by `gateRemaining`, skipped for landings (they answer to the design gate; two rails on one conversation fight), and the first clean verdict stamps `builder_conversations.build_reviewed_at` and retires the rail so later tweak turns are never re-reviewed.

**The technical sheet is what the critic SEES** — `AppDocs::criticSheet` is `TechnicalWriter::write(omit: ['actions', 'runtime'])`, ids already joined to names, a fraction of the manifest's tokens. This is the load-bearing fact of the whole area: anything the sheet leaves out is something the reviewer cannot verify and will report as missing. `TechnicalWriter::fieldDetail` and `blockSubject` therefore print **whatever a node carries beyond its structure**, generically, rather than a list of interesting properties — because that list WAS the bug. `capture` was not on it, so a barcode-scanning string read as a plain string and a signature pad read as a file upload; the reviewer reported both as missing on an app that had them, and the review turn sent to "fix" them deleted a page instead. Same for permissions: the CRUD letters alone cannot say which rows a role sees or which fields are kept from it, so `row_filter` and `field_restrictions` get their own table. `ManifestDiffService` had the identical whitelist and the identical hole. **When you add a field or block capability, do not add it to a `*_STRUCTURE` list** — those are for things printed elsewhere; anything else appears for free, which is the point.

**Design lint** — non-blocking `design_smell` warnings from `ManifestValidator`, returned by `propose_change` alongside a message telling the model not to report success until each is fixed or explained. They are warnings, not errors, so a pre-existing one never makes an app unsaveable. Each came from a real build: R1 a page of nothing but headings/spacers · R3 a block needing `{{params.X}}` nothing provides · R4 two fields of one object wearing the same name · R5 a total built by summing unit prices · R6 a board grouped by a select nothing fills in · R11 a capture typed flat (signature/photo as text, lat+lng as a `number` pair — matched through `SemanticLexicon`, so the app's locale decides the vocabulary) · R12 a `navigate` whose target no page serves · R13 an object with a list block and no `record_detail`, which fires only when the app already uses detail pages for something else (none at all is a design choice; four out of six is an oversight) · R14 a second OVERVIEW page — aggregate blocks, nothing that touches one record — whose objects are a SUBSET of an earlier overview's, i.e. it charts nothing the first already charts. R12 and R13 walk the block tree GENERICALLY: descending only `blocks` misses everything inside `tabs[i].blocks`, which is where the central object's list lives on every scaffolded app — the first cut of R13 did exactly that, and a by-hand review of the same manifest missed the same link. `ScaffolderQualityTest` asserts a freshly scaffolded app raises zero design warnings; keep it that way.

**The platform used to create the defects its own rules then reported.** The recurring findings across eight runs of one brief were not the model wandering: `/pos` came from `AppScaffolder::detectAndBuildPosEconomics`, which invents a point-of-sale module from the structural shape order←line→product; `/dashboard_2` came from `add_dashboard_page`, which appended unconditionally and let `uniqueSlug` name the collision with the dashboard the scaffold had already built. Each one was then reported by the critic or by R14 and deleted by a review turn — **the platform arguing with itself, once per build, at the model's expense**. Two rules follow. **A generator consults the rule that will judge it**: `add_dashboard_page` now asks `ManifestValidator::overviewPages()` (R14's own predicate, made public for this) whether an overview already covers the data, and rewrites that page — keeping its id, slug and path so existing links survive — instead of adding a second; nested EITHER way counts, because R14 only checks a later page against earlier ones and a richer second dashboard that swallows the scaffolded one slips past it. `as_new_page:true` is the escape hatch. **Speculative modules answer to the REQUEST, not to the shape of the data**: the POS module needs the brief to actually ask to sell (`pos_intent` in `SemanticLexicon` — deliberately far narrower than `commerce`, which covers order/line/service/ticket and so matches nearly every operations app, which is exactly why the existing triad guard passed all four times). With no request text — templates, hand-assembled specs, the older tests — behaviour is unchanged.

**R14 is the rule the critic could not be.** `serviciocampo_v7` shipped two dashboards — `/` over five objects and `/dashboard_2` over `ordenes`, which `/` already charted — against a brief asking for one. The critic READ that manifest and passed it, correctly: its `unrequested` direction hunts invented SUBJECT MATTER ("a whole page, object or workflow belonging to some other product", plus "when in doubt, leave it out"), and a second view of the app's own orders is not another product. The category it would have needed is "the same thing twice", which is not a judgement call — so it is decided in code. Subset, not overlap, is what makes it precise: a real second dashboard brings data the first does not have, and «Ventas» beside «Operaciones» must stay silent. Do not answer this class of miss by widening the critic's prompt; the whole area's rule is that a rail beats a sentence.

**The design lint reaches an EXTERNAL agent too.** The builder's own `propose_change` has returned these warnings since the rules were written; the MCP one returned them only when the patch was REJECTED, so a patch that applied WITH warnings came back as a bare `{"applied":true}`. Found by exercising R12/R13 through the real MCP path: an app whose «Abrir» button pointed at a page that does not exist and whose object could be listed but never opened was answered with success and nothing else — both warnings sat in `audit_app`, which a caller has no reason to think it must ask. Same validator, same codes, same instruction not to claim success on top of them (`tests/Feature/Mcp/ProposeChangeWarningsTest.php`).

**The failure ledger** (`build_findings`, tenant schema under RLS; `BuildFindingLedger`, `BuildFinding`) keeps the three failure signals the builder already produces and used to discard after one turn: `patch_rejected` (propose_change refused the ops — the model believed something untrue about the manifest), `design_smell` (applied, but the validator warned), and `critic` (coded `missing` / `unrequested`). `ai_usage_events` records what a build COST; nothing recorded whether it came out right, so a recurring defect could only be found by reading conversations by hand. Warnings fold once per turn (they are recomputed over the whole draft on every call, so counted per call one unfixed smell swamps the rankings); rejections do NOT fold, because the same patch refused twice is two attempts and that repetition is the pattern worth seeing. Attributed to the BUILDER's model, not the critic's. Read it with `get_build_quality` (org-wide by default — no single build is big enough to show a pattern; `top_codes` ranks them, `by_model` divides findings by the builds behind them). **Nothing here is ever fed back into a prompt.** It is telemetry for a human deciding which rail to write next; the loop ends in deterministic code or a line of `FrameworkReferenceTool`, not in more reading for the model.

**The typed data-model edits reach the builder** (`add_field`, `add_object`, `add_relation`, sharing `ManifestEditor`'s `applyAdd*` pure functions with the MCP tools of the same name). They existed from the day they were written and only the MCP server had them, so the BUILDER — which writes these edits dozens of times per app — hand-enumerated every `field_id` into every form, table and record_detail. That enumeration is the largest rejection class in the ledger: measured over four runs of one brief, one invented id sprayed across TWELVE pointers in a single call. Two attempts to teach the model to write slugs instead both failed outright (the tool description, then the error text: zero slugs written across four builds), so the work is REMOVED rather than taught — these take an object slug and a field name and there is no id to get wrong. They compute against the turn's RUNNING DRAFT and stack ops onto it (`EditsTheDraftModel`), never writing their own version: the builder's unit of work is the turn, and a tool that persisted on its own would split one turn across two versions and break undo. **Creating an object is the BASIC type path** (`normalizeFields`, deliberately restricted) while `add_field` is the typed one (`normalizeField`, which keeps a `config` bag) — so `capture`, `expression` and friends are set by a follow-up `add_field`, and a `config` passed at create time is now REPORTED as a coercion instead of dropped in silence. That silent drop is why every run of the field-service brief ended with the critic reporting a plain `sku` where a scannable one was asked for. Tests: `tests/Feature/Ai/Tools/Builder/TypedModelEditToolsTest.php`.

**A patch may name things by slug** (`ManifestRefResolver`, run on the patched document in BOTH apply paths before validation). `unresolved_ref` was 18 of 30 rejected patches in the ledger — the most expensive thing a build does wrong, since each rejection re-bills the whole cached conversation. The rejections were not carelessness, they were pattern-completion: one build aimed a relation at `obj_01kzaeq206ma38yxvtp0ewy24x`, the APP's ULID with the prefix swapped; another wrote `fld_…1r3qvz` where the real id was `pag_…1r3qwf`, then repeated that invented id across six pointers in one call. **The id FORMAT is the cause** — every ULID minted inside one build shares its timestamp prefix, so all of an app's ids open with the same ten characters and differ only in the tail, which is the worst possible string to copy. So the model no longer copies them: anywhere an id is expected a slug now works, because the resolver holds the thing the model lacks — the SCOPE (a column's `field_id` resolves against its block's queried object, so `"sku"` is exact where `fld_…akc3` could only be guessed). **Ids always win and nothing is ever guessed**: a value matching a real id is left alone, only a value matching NO id is looked up as a slug, and a slug matching nothing is passed through for the validator to reject exactly as before — a silent mis-wiring would be worse than the rejection it replaces. The id pattern cannot do this job (`fecha_inicio_programada` satisfies it), which is why existence, not shape, is the test. Both tool descriptions say so, or the affordance goes undiscovered. Tests: `tests/Unit/Services/Manifest/ManifestRefResolverTest.php`, `tests/Feature/Mcp/ProposeChangeSlugRefsTest.php`.

**Measuring a change here needs the benchmark, not one build.** `resources/benchmarks/app-suite.php` (16 hand-authored briefs with hand-authored expectations, `field_service` among them) + `php artisan benchmark:apps --repeat=N --baseline=…` is the acceptance harness, and its checks are DETERMINISTIC — a model grading its own homework measures the grader. Use it. Two runs of the identical brief on the identical code produced 8 and 60 ledger findings, so **a single build cannot tell you whether a change helped**, and the ledger's own counts make it worse: rejections do not fold by design, so ONE invented id sprayed across twelve pointers reads as twelve findings. That is right for spotting repetition inside a build and wrong for comparing builds — count `patch_rejected` per CALL when comparing. The signal that did survive the noise across four runs was the CRITIC's count (10.5 per build before the permissions-sheet fix, then 2–6), because it measures the outcome rather than the process.

**Working notes**: a rejected patch names WHERE it landed (`ProposeChangeTool::locationOf` adds `at` to every error — "`/pages/0` is the page `refacciones_detail`"), because pages are addressed by index and reasoned about by name, and five consecutive rejections aimed at one page and landing on another is how a build gives up and reports done. `BuildPlan::toContextLines` emits each step's id, or `target_plan_steps` cannot be called. `AppScaffolder::FIELD_CONFIG_PROPS` is the whitelist of field config the scaffolder may pass through; it silently dropped `capture`, and `FieldConfigWhitelistTest` now derives it from the schema and compares — a lock, not a snapshot. Measured on four runs of one verbatim brief (`serviciocampo_v4`…`v7`, Haiku builder + Opus critic): $1.31 / $2.35 (6 critic passes) / $1.24 / $0.84, with the last two at exactly one pass per applied turn. Tests: `tests/Feature/Apps/BuildCriticRailTest.php`, `tests/Feature/Builder/BuildFindingLedgerTest.php`, `tests/Feature/Mcp/GetBuildQualityToolTest.php`, `tests/Unit/Services/Apps/AppDocsTest.php`, and the R-rules in `tests/Unit/Services/Manifest/ManifestValidatorTest.php`.

## Offline Runtime

A built app OPENS without a signal, shows the last thing it saw, and HOLDS what you write until there is a signal again.

`public/sw.js` is hand-written (no Workbox: the caching is three rules long and a build-time dependency to express them costs more than it saves). It is registered at the root so it can serve the runtime's own document, but **it only ANSWERS for `/r/…`, `/a/…` and immutable assets** (`/build/`, `/fonts/`) — admin, builder, auth and every API fall straight through, because a stale admin screen is worse than no admin screen and a cached auth response is a bug with a CVE number. It keeps **only GET, only 200, never `no-store`**, and never serves cache ahead of network: a technician acting on a work order that was reassigned an hour ago is a worse failure than an app that will not open. **Nothing is kept forever** — past 30 days a page is dropped rather than served (`fresh()`, swept on activate AND checked on serve, because the sweep runs when the worker starts and a tab left open for weeks never restarts it). That is a retention bound, not a freshness one: staleness is handled by the bar saying how old the data is, while this is about the phone left in a drawer still holding somebody's customer list. The write queue is exempt — ageing out unsent work would be silently destroying it. The cache key includes `X-Inertia-Partial-Data` because **Inertia asks for the same url twice** (the page, then deferred `blockData`) — keyed by url alone the second answer overwrites the first and the page comes back with no shell. Sign-out purges everything (`sapiensly:purge`): the cache holds rows one person was allowed to see and the next person at that device is not necessarily them.

Cached rows are never rendered silently. `OfflineBar` (only while offline, never as a permanent "you might be offline" notice people stop reading) prints how old they are, read from the `x-sapiensly-cached-at` stamp via Cache Storage from the PAGE — reachable from both sides, so no message round-trip with the worker and it still answers while the worker is installing. When the age is unknown it says the weaker true thing rather than inventing a time.

The install manifest is **per app** (`AppWebManifestController`, `/r/{slug}/manifest.webmanifest`): a technician installs «Servicio Campo» and gets that app's name, icon and start url, `scope`d to `/r/{slug}` so the installed window never captures the platform around it. One shared platform icon would make the install worthless the moment an organization built a second app.

The worker exposes its predicates on `self.__swInternals` — a service worker is a script the browser runs, not a module anything can import, so the two rules that matter would otherwise be checkable only by driving a real browser offline.

**The write queue** (`resources/js/runtime/offlineQueue.ts`, IndexedDB) turns "opens offline" into "is usable offline". Three rules, each the reason a piece of it exists. **A queued write is never reported as saved** — `WriteResult.queued` is deliberately not `ok`, so a caller has to look at it and say "saved on this device"; a green toast for something no server has agreed to is the failure this feature would otherwise introduce. **A replay must not write twice** — every entry carries an idempotency key from the moment it is queued, because a phone that loses signal mid-request cannot tell "never arrived" from "arrived, answer lost", and without a key the client must choose between a duplicate work order and a lost one. **A refused write is not silently dropped** — it leaves the queue for a rejected list the `OfflineBar` names entry by entry, because the only thing worse than a write that fails is one that disappears. The queue is bounded at 200 entries and attachments at 100 MB, so a device offline against a chatty app fails a write plainly instead of hitting the browser's own opaque quota mid-write. The flush is FIFO and **stops at the first entry that could not reach a server** (a later write may update the record an earlier one created); `classify()` decides that — no status, 5xx and 429 are all retryable, everything else is a considered refusal. The one exception is read from a HEADER, not from the status: the dedupe middleware's in-flight 409 carries `Idempotent-Retry`, because a bare 409 classified as a refusal would retire a write that just needs asking again in a moment, while a real 409 from the app must stay a refusal.

**Queueing is opt-in per call, not a property of the transport.** `useRuntimeWrite` defaults it off because most of what goes through that seam must NOT wait: `/extract` is a model call wanted now, `/bulk` acts on a selection that is stale by the time the signal returns, a form submit has its own one-shot guard. Only a manifest action sequence opts in, and `mayWaitForASignal` narrows even that — `run_workflow` is refused because it reaches OUTWARD (an email about a visit that already ended has no undo), and the demo environment is refused because a queued write is a plain POST with no environment binding, so the flush would land sandbox work wherever the session then points.

**A photo taken where there is no signal** (`offlineFiles.ts`) is what makes the queue worth having — a work order closes with the meter's photo and the customer's signature on it, and both are uploads. An upload that cannot reach a server returns a `filq_…` id that looks real to everything downstream (field, preview, record payload) and refers to a blob held on the device; just before the queued write is sent, the queue uploads each held file and **substitutes the whole value**, not the id alone — replacing only the id would store a `blob:` url from a page that closed hours ago in the record forever. Four rules follow from "a record pointing at a photo that failed to upload is worse than one not written yet": attachments go first and the write only if they ALL land; an unreachable upload leaves the payload byte-identical so the entry retries whole; a refused one rejects the write with the server's reason; missing bytes (a purge, an eviction) reject rather than send a record that points at nothing. Held bytes are capped at 100 MB per device — a number we chose so the field can say *why* it refused, instead of hitting the browser's opaque quota mid-write. Holding is opt-in per call for the same reason queueing is: `BlockForm` uploads a document for a MODEL to read, and bytes held for a reading that will never happen are a photo attached to nothing. The preview marks a held file (`offline_file_held`), because a preview identical to an uploaded one has somebody drive away believing the photo is in the record.

Server-side, `IdempotentRuntimeWrite` middleware sits on all four replayable write surfaces — `actions` and `uploads`, under both `/r` and `/a`. Uploads need it too: a retried upload would leave an orphan blob in tenant storage behind a record pointing at the other copy. A test asserts the exact set, because being *wired* is the part that silently goes missing. It claims the key with `TenantCache::add` (atomic — check-then-write would let two concurrent copies through), returns 409 while the first attempt is in flight, and replays the stored response verbatim afterwards. **Only 2xx is remembered**: a 422 replayed produces the same 422 from the same input, and freezing a 500 into a permanent answer would turn one bad minute into lost work. It is a dedupe window, not a transaction log — if Redis loses the key inside the week, the replay writes again. Scoping through `TenantCache` is load-bearing rather than decorative: app slugs are unique PER ORGANIZATION, so two tenants really can both be at `/r/campo/actions`, and a shared keyspace with a client-chosen key would answer one with the other's response body.

**What an app may leave on a device is the owner's call** (`settings.offline`, read only by `App\Support\Offline\OfflinePolicy`, set from the builder's **Access** panel — beside the access mode, because it is the same kind of statement: that one says who may open the app, this says which of its data is still on their phone tomorrow. `AppAccessController::updateOffline` writes it as a manifest patch, so it is a reversible version like everything else, and the default is written as ABSENCE — setting the default clears the key and creates no version at all, so "unset" keeps reading as "nobody asked"). A cached page is tenant rows on a phone's disk in the clear and a queued write is somebody's data in IndexedDB; for a work order that is the right trade, for a payroll run it is not. **Default ON** — offline shipped on for every app, and one that silently stopped working in the basement it was built for is a worse failure than the one this guards against — so the opt-out is explicit: `enabled:false` for the whole app, or `exclude_objects: [slug]` for just the data that must stay server-side. The granularity is the OBJECT because "salaries must not sit on a phone" is a fact about DATA, and stated about the data it keeps holding when someone adds a second page that shows it: a page is refused if anything **on it reads** an excluded object, derived by walking the block tree generically (`objectIdsIn` — a chart carries one per series, a tabs block hides them a level down, and the list of places is exactly the list that goes stale).

**The enforcement point is `no-store`, not the client.** The controller marks the request and `NoStoreWhenOfflineIsRefused` (prepended to `web`, like `VaryOnNegotiatedLanguage`) stamps the header on the way out — a middleware rather than a header on the return value because the runtime controller returns an `Inertia\Response` and the PDF renderer delegates to it and then chains `->with('printing')`; converting it to an HTTP response in the controller broke exactly that. The worker's existing storability rule does the rest — a worker installed last month honours it as well as one installed today, which no client-side rule can promise. The client gets the policy too (`offline` prop → `provide('offlinePolicy')`), but only for the two decisions the server cannot make, because it never sees the request that was not made: whether to HOLD a write (`mayWaitForASignal`) and whether to hold the photo attached to one (`FormFieldInput`, whose default with no `objectId` is NO — the questionnaire form posts to a one-shot endpoint that is never queued, so bytes held for it would never be sent).

Sign-out clears both stores through one function (`lib/offlineStorage.ts`) — clearing one is worse than clearing neither, and the cache purge originally shipped with no caller at all.

Tests: `resources/js/runtime/serviceWorker.test.ts` (loads the real `public/sw.js` in a `vm` context, so a rule that drifts out of the worker fails there), `offlineQueue.test.ts` and `offlineFiles.test.ts` (over `fakeIndexedDb.ts`, a seven-method in-memory shim — jsdom has no IndexedDB and `fake-indexeddb` would be a dependency for a loop), `useRuntimeWrite.test.ts`, `mayWaitForASignal.test.ts`, `useOfflineStatus.test.ts`, `tests/Unit/Support/Offline/OfflinePolicyTest.php`, `tests/Feature/Apps/OfflinePolicyHeaderTest.php`, `AppOfflineSettingTest.php`, `IdempotentRuntimeWriteTest.php`, `AppWebManifestTest.php`. The builder learns about `settings.offline` from the `offline` topic in `FrameworkReferenceTool` — keep it in sync with any behaviour change here.

## The Device

An app runs on the phone in somebody's hand, and the hand is usually doing something else. Everything in this area is the same shape: a way into a field that is not the keyboard, or a way out of the app that is not the screen.

**One rule covers all of them — the ordinary input stays behind the capture.** A desktop, missing hardware, a refused permission: each leaves a box somebody types into rather than an app somebody cannot use, and the stored VALUE is the plain field's either way, so filters, tables, exports and validation need no special case. That is why a capture is never risky to author and why `capture` is a property on an existing type rather than a type of its own. Every failure path in `resources/js/runtime/device.ts` is written to return what happened instead of throwing, because a click handler that throws is a button that does nothing and says nothing.

**Captures** (`capture` on a field; the catalog is `ListAvailableFieldTypesTool`, the schema is the enum per field type): `string` takes `barcode` (camera + handheld-gun keyboard-wedge detection), `nfc` (`NDEFReader`, its text/url payload or the chip's serial number — for what a printed label cannot survive), `contact` (the OS picker; the page never sees the address book, only the one entry chosen) and `dictation`; `email`/`phone` take `contact`; `long_text` takes `dictation`; `number` takes `scale`; `file` takes `camera`, `signature` and `screenshot`, plus `stamp:true` beside `camera`; `geo` takes `auto`.

**`dictation` is the browser's recogniser, not a model** (`useDictation.ts`) — free, instant, nothing uploaded, which is what the findings field somebody fills in next to a machine needs. Deliberately not the same feature as a form's `fill_from_voice`, which records audio and spends a model call to fill a whole form. Only SETTLED phrases reach the box and they APPEND: a note is dictated in pieces, and a box filled from interim guesses ends up with each phrase written three times.

**`stamp:true` is the one reason to give up the OS camera.** `capture:"camera"` normally hands over to the phone's camera app, which focuses and exposes better than anything we would build — and returns a finished JPEG, with no moment in the flow where anything can be written INTO the image. For a photo that is evidence (a meter reading, damage on arrival) that moment is the whole point, so `CameraCapture.vue` holds the shutter and `photoStamp.ts` burns the date, time and coordinates into the corner. The location is asked for while the viewfinder is open, never waited on: a refusal writes the time alone rather than failing the photo.

**`scale` reads a machine, and refuses a number nobody meant** (`scale.ts`, Web Serial). Two rules, both tested: a frame the device marks unsettled (`US`, a leading `?`) is refused because a weight read while the pan is moving is wrong with total confidence, and two agreeing readings are required because one line can be the tail of the previous weight. A DETACHED minus sign (`ST,NT,-  0.250kg`, what a tared scale really sends) is kept — dropping it stores the opposite of what the machine said, which is what the test caught.

**`geo` + `capture:"auto"` is the only capture that happens without being pressed**, and it is fenced accordingly: the author has to ask for it, it only fires on an empty value (an edit must not overwrite where somebody actually WAS with where they are now), and the form prints a line saying it did it. A location taken without being asked for is the difference between recording work and tracking staff, and the only honest version is one the person can see happening.

**Device actions** (`share`, `copy`, `speak`, `toggle_fullscreen`) run entirely client-side in `useActionExecutor`. `share` is the one that matters: with `page_slug` it fetches the page's PDF and shares it as a FILE, which is the only route from this app to WhatsApp — and WhatsApp is how a signed delivery note reaches a customer who has no login and never will. It degrades in one direction only: no share sheet copies the link, a file that cannot be shared falls back to the download. `pdfHref` is shared with `download_pdf` because written twice they drifted immediately (the first `share` forgot `paper`, so a shared delivery note came out A4 while the printed one came out on label stock).

**A client action must be in the controller's `CLIENT_SIDE` list the day it is added**, in both `AppActionController` and `PublicAppActionController`. A sequence containing ONE server action is posted whole, and anything not on that list comes back `unknown_action` with the whole sequence failed — so "create the order, then share it" would save the record and then report failure, which is the worst available pair of outcomes. Being on the list is also what lets `{{record.id}}` resolve in a `share`/`copy`/`speak` written after a create: `resolveClientAction` fills `text`/`title`/`url` server-side, and the client has never seen that id.

**`keep_awake` is per PAGE** (`useWakeLock.ts`), for the screen worked ON rather than looked at. The load-bearing part is re-acquiring on visibility: the browser drops a wake lock every time the document hides and hands nothing back, so an app that took one and got a phone call has no lock and no idea. Never app-wide — the app also has a list somebody leaves open on a desk.

**`require_identity` asks WHO, where a confirm dialog asks WHETHER** — the fingerprint, face or PIN the device already knows, in front of a refund, a write-off, an override or an irreversible delete. Verified server-side (`IdentityConfirmation` + `App\Support\Identity\WebAuthnAssertion`) against a challenge this server minted, because a browser-side check that a fingerprint was accepted is worth nothing: the thing sending the request is the thing that would be lying. Three rails, each with a failure behind it: the gate runs BEFORE any action in the sequence (a sequence that deletes and then asks has already deleted), the challenge is single-use (one fingerprint approves one action, not everything done in the next two minutes by whoever holds the phone afterwards), and `mayWaitForASignal` refuses it like `run_workflow` (an approval is a statement about a moment). USER VERIFIED is required, not just USER PRESENT — a screen that happens to be unlocked in a shared van is not the claim being made. No CBOR and no library: `getPublicKey()` hands over SubjectPublicKeyInfo and openssl reads it; the attestation statement is deliberately unchecked, since it answers which manufacturer made the authenticator, which is hardware procurement, not identity. The tests are mostly refusals, because a gate that fails open is a gate people trust.

**Push notifications** are the only way to reach somebody with the app CLOSED, which is what the offline runtime is missing: the app works in the basement, and nothing tells anyone to go down there. It is a CHANNEL on `notify.send`, not a new step — `push` beside `email` and `in_app`. `App\Support\Push\WebPush` implements RFC 8291 (`aes128gcm`) and RFC 8292 (VAPID ES256) over openssl rather than a package: it is a hundred lines of well-specified steps, and the private scalar is handed to openssl as a SEC1 PEM with the public point left out so openssl computes it — which is why none of this needs big-integer arithmetic. **The test proves it in the direction that cannot be faked**: it checks the RFC's published key pair agrees with itself under the curve, then encrypts to that subscription and opens the result with the RFC's own private key using a receiver written out separately in the test file. Asserting a pinned body would have proved only that the code agrees with itself. A subscription is a BROWSER, not a person (`push_subscriptions`, tenant schema under RLS, keyed by endpoint hash so a browser re-registering does not accumulate rows), permission is asked for from a TAP in the notification bell and never on load (a refusal in Chrome is close to permanent), and a `410`/`404` deletes the row — a browser that was uninstalled keeps its endpoint alive as an error for ever. A send that reached nobody says which kind of nobody: "no device has allowed notifications" (the normal first state) is a different problem from "the push service would not deliver".

**Working notes**: `FIELD_CONFIG_PROPS` in `AppScaffolder` is derived-and-compared by `FieldConfigWhitelistTest`, so a new `capture` on a new type fails there until the whitelist grows — it caught four gaps in one go here. `TechnicalWriter` prints whatever a field or a PAGE carries beyond its structure, so `capture`, `stamp` and `keep_awake` reach the build critic for free; the page half of that is new, and `keep_awake` was invisible until it existed. `notificationFrom` is exposed on `self.__swInternals` for the same reason the caching predicates are. Tests: `device.test.ts`, `useWakeLock.test.ts`, `useDictation.test.ts`, `scale.test.ts`, `blocks/photoStamp.test.ts`, `serviceWorker.test.ts`, `tests/Unit/Support/Push/WebPushTest.php`, `tests/Feature/Apps/PushNotificationTest.php`, `IdentityConfirmationTest.php`. The builder reads the `device` topic in `FrameworkReferenceTool` — keep it in sync with any behaviour change here.

## Database & Multi-Tenancy

Tenant isolation is enforced **at the database layer**, not just in application code. Three Postgres roles map to three Laravel connections against one database, with two schemas:

| Role / connection | Schema | Purpose |
|---|---|---|
| `postgres` → `pgsql` | both (owner) | Migrations & DDL only. Bypasses RLS. |
| `platform_app` → `platform` (default) | `platform` | Control-plane runtime: accounts, permissions, providers, and authored *definitions* (agents, tools, knowledge_bases, apps + manifests, chatbots, channels, integrations, flows). No RLS — isolation is structural (role has USAGE on `platform` only). |
| `tenant_app` → `tenant` | `tenant` | Tenant *data* runtime: records, documents, chunks, conversations/chats/messages, debates, widget/whatsapp/integration-execution/workflow rows. **Protected by Row-Level Security.** |

`platform_app` and `tenant_app` each have USAGE on **only their** schema, so a mis-routed query fails loudly (permission denied) rather than leaking rows. RLS then isolates rows *within* the tenant schema by `organization_id` (business mode) or `user_id` (personal mode), mirroring `HasVisibility::forAccountContext`.

**Key pieces:**
- **`app/Support/Tenancy/Schemas.php`** — single source of truth mapping every table to platform/tenant. `Schemas::tenantTables()` drives the relocate / tenant-key / RLS / auto-fill-trigger migrations (they iterate it).
- **Tenant models** use the `UsesTenantConnection` trait (→ `tenant` connection). Platform models default to `platform`. When adding a model, decide its schema and pin accordingly.
- **`app/Support/Tenancy/TenantContext.php`** sets the RLS GUCs (`app.organization_id` / `app.user_id`). `BindTenantContext` HTTP middleware sets them from the authenticated user; a global queue payload hook (in `AppServiceProvider`) propagates the scope to every job; the WhatsApp webhook job derives scope from its channel.
- **Auto-fill trigger**: a `BEFORE INSERT` trigger fills `organization_id`/`user_id` from the session GUCs when unset, so tenant-row inserts satisfy RLS `WITH CHECK` without per-write code.
- **Tenant cache** — **`app/Support/Tenancy/TenantCache.php`** (facade `App\Facades\TenantCache`) is the Redis-layer analog to RLS: it transparently namespaces every key with the active scope (`t:org:{id}:` / `t:user:{id}:`, derived from `TenantContext` like `HasVisibility::scopeForAccountContext`) and **fails closed** — no scope set ⇒ `TenantCacheScopeMissingException`, never a shared key. Unlike the database, Redis is one shared keyspace with no structural isolation, so this is the *only* sanctioned way to cache tenant-derived data. `forOwner($org, $user)` scopes explicitly (queues/admin). Queue jobs already restore the scope via `EstablishTenantContext`; rate-limit buckets are keyed by `organization_id`.
- **BYODB** (a tenant's own external DB) is separate: config in `platform.cloud_providers`, built as the `byodb_runtime` connection.

**Working rules:**
- **Migrations run as the owner**: the app default connection is `platform` (least-privilege, can't own/relocate tables). `AppServiceProvider::forceOwnerConnectionForMigrations()` auto-redirects `migrate*` / `db:seed` / `db:wipe` to the owner `pgsql` connection when `--database` is omitted, so a plain `php artisan migrate` is safe — but passing `--database=pgsql` explicitly is still fine and always wins. Without this, create-table migrations land owned by `platform_app` and the relocate step fails with `must be owner of table ...` (notably on Supabase).
- **Adding a tenant table**: add it to `Schemas::TENANT_TABLES`, then add a migration that relocates it to `tenant`, adds the tenant key + index, enables RLS + the `tenant_isolation` policy, and the `fill_tenant_key` trigger (follow the `2026_06_04_9000xx_*` migrations as templates).
- **Caching tenant data**: use `TenantCache` (or the facade), never `Cache::` directly — `Cache::` is for global/platform values only (e.g. `app_setting.*`). A tenant-derived value under a shared `Cache::` key leaks across tenants because the cache sits *in front of* RLS. Tenant-wide flush isn't supported via the prefix; invalidate by explicit key.
- **Local/Supabase**: roles are created idempotently (pre-exist on Supabase). On Supabase, point the `tenant` connection at the **session pooler** so `SET app.*` persists per request; otherwise wrap tenant work in `TenantContext::runScoped()`. Override role names via `PLATFORM_DB_ROLE`/`TENANT_DB_ROLE` if they differ.
- **Tests**: the runtime connections run as the owner (RLS bypassed) and are aliased to one session in `tests/TestCase.php`; RLS itself is covered by `tests/Feature/Tenancy/RowLevelSecurityTest.php`, which connects as the real `tenant_app` role. Tests require PostgreSQL (not sqlite).

### Frontend Structure
- **Entry point**: `resources/js/app.ts` - initializes Inertia and Vue
- **Pages**: `resources/js/pages/` - Inertia page components (Dashboard.vue, Welcome.vue, settings/)
- **Layouts**: `resources/js/layouts/` - AppLayout.vue with sidebar/header variants, settings/Layout.vue
- **Components**: `resources/js/components/` - App-specific components; `ui/` subdirectory contains shadcn/vue components (excluded from linting)
- **Composables**: `resources/js/composables/` - Vue composables (useAppearance, useInitials, useTwoFactorAuth)
- **Types**: `resources/js/types/index.d.ts` - TypeScript interfaces for User, Auth, NavItem, AppPageProps
- **Utilities**: `resources/js/lib/utils.ts` - `cn()` for class merging, URL helpers

### UI Component Library
Uses reka-ui (shadcn/vue) components with Tailwind CSS v4. Component variants use `class-variance-authority`. The `cn()` utility combines `clsx` and `tailwind-merge` for conditional classes.

### Styling
- Tailwind CSS v4 with CSS variables for theming (light/dark mode via `.dark` class)
- Theme defined in `resources/css/app.css` with CSS custom properties
- Uses `tw-animate-css` for animations

### Wayfinder
The project uses `laravel/wayfinder` for type-safe routing between Laravel and the frontend.

## Code Style

**Language Requirement**: All code, comments, commit messages, and documentation must be written in English.

### PHP
- Uses Laravel Pint for formatting
- PHP 8.4 required
- Tests use Pest

### TypeScript/Vue
- ESLint + Prettier with TypeScript support
- Single quotes, semicolons, 4-space tabs
- Vue single-file components with `<script setup lang="ts">`
- Multi-word component names rule disabled
- Explicit `any` types allowed

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v2
- laravel/ai (AI) - v0
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/mcp (MCP) - v0
- laravel/passport (PASSPORT) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/socialite (SOCIALITE) - v5
- laravel/wayfinder (WAYFINDER) - v0
- laravel/boost (BOOST) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v2
- tailwindcss (TAILWINDCSS) - v4
- vue (VUE) - v3
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- laravel-echo (ECHO) - v2
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== fortify/core rules ===

# Laravel Fortify

- Fortify is a headless authentication backend that provides authentication routes and controllers for Laravel applications.
- IMPORTANT: Always use the `search-docs` tool for detailed Laravel Fortify patterns and documentation.
- IMPORTANT: Activate `developing-with-fortify` skill when working with Fortify authentication features.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
