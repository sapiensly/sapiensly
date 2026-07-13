<?php

namespace App\Ai\Tools\Builder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * On-demand authoring reference for the Builder agent. The detailed,
 * situational rules (forms, workflows, derived fields, visual design,
 * connected objects, visual review, verification, a worked example) used to
 * live in the always-on system prompt — ~5k tokens re-billed every turn even
 * when the turn only renamed a field. They now live here and the model pulls
 * ONLY the section it needs for the current task, by topic.
 *
 * Keep each section self-contained and concrete; the model treats whatever
 * this returns as the authoritative rules for that area.
 */
class FrameworkReferenceTool implements Tool
{
    /**
     * @var array<string, string>
     */
    private const TOPICS = [
        'forms' => <<<'TXT'
DATA ENTRY (forms, buttons, modals):
- To capture new records, the canonical pattern is: a button on the page with on_click=[open_modal], then a modal whose blocks array contains a form (mode=create) whose on_submit=[create_record, close_modal, show_toast, refresh]. Inline forms (directly on the page) are valid too but less common.
- For per-row actions inside a table ("Marcar completada", "Editar", "Borrar"), use ACTION COLUMNS, not workarounds. Add an entry to table.columns with `{id, type:"action", label, icon?, variant?, on_click:[...]}`. Inside on_click, address the clicked row with {{row.id}} (record id) and {{row.data.<slug>}} (any field of that record). Example mark-done: `[{type:"update_record", object_id:"obj_...", record_id_expression:"{{row.id}}", values:{completada:true}}, {type:"refresh"}]`. NEVER fake action buttons with formula fields expression:"true" or buttons placed outside the table — they don't work.
- The canonical EDIT-from-table pattern: action column → open_modal {modal_block_id, params:{record_id:"{{row.id}}"}} → modal contains a form mode=edit with record_id_expression="{{params.record_id}}".
- ALWAYS call `list_available_actions` before composing on_click or on_submit if unsure which actions exist or how to write their values. Inventing actions fails validation.
- In action.values, reference form inputs with {{form.<slug>}}, page params with {{params.<X>}}, and the current user with {{current_user.id}}. Resolved server-side by ExpressionResolver — see the patterns from list_available_actions.
TXT,

        'workflows' => <<<'TXT'
WORKFLOWS (automation):
- The user wants a workflow when they say "when X happens, do Y", "automatically …", "every time a … is created/updated, …", or want a button that runs a multi-step routine. Compose them as workflows[] at the manifest root.
- ALWAYS call `list_available_triggers` and `list_available_steps` before proposing a workflow. The engine refuses unknown trigger or step types. `list_available_triggers` returns every type, its params/example, AND a `notes` block (id resolution, external setup, scheduler/filter caveats) — read the notes, they prevent silent no-ops.
- Trigger families (one trigger per workflow): click → `manual`; data → `record.created`/`record.updated`/`record.deleted` (optional `filter`); time → `schedule` (cron) and `record.date_reached` (N before/after a date field); external push → `webhook.inbound` (generic signed URL), `integration.event` (typed provider webhook on an Integration), `channel.message_received` (WhatsApp/widget inbound), `email.inbound` (any inbound-email provider); external pull → `integration.poll` (poll a connected tool on a schedule, fire per new item). The id a trigger references (object_id/field_id/integration_id/tool_id/channel_id) must be a REAL one — resolve it (manifest objects, `list_integrations`, `list_tools`, the org's channels), never invent it. integration.event/email.inbound also need an inbound webhook secret set on the Integration; schedule/date_reached/poll need the host scheduler running.
- CONTEXT BOUNDARY: a workflow only sees `{{trigger.…}}`, `{{vars.…}}`, `{{steps.<id>.output.…}}` and `{{current_user.…}}`. It does NOT see `{{form.…}}`, `{{params.…}}` or `{{row.…}}` — those are UI-runtime roots and resolve to null inside a workflow (rejected at save). To use a form's values in a workflow, the `run_workflow` action MUST forward them via its `input` map, and the workflow reads them from the trigger. Example: a "Buscar" form → `on_submit: [{run_workflow, workflow_id, input: {min: "{{form.rango_min}}", max: "{{form.rango_max}}"}}, refresh]`; inside the workflow a step reads `{{trigger.min}}` / `{{trigger.max}}`. Forgetting the `input` map (or using `{{form.…}}` in the steps) makes the workflow run on empty data — the classic "toast says done but nothing happened" bug.
- Typical patterns:
  - Audit log: trigger=record.created on object X → step record.create on audit_log object with the original record's fields via {{trigger.record.data.<slug>}}.
  - Manual button: trigger=manual + button on a page with on_click=[run_workflow, show_toast, refresh].
  - AI enrichment: trigger=record.created → ai.complete with prompt referencing {{trigger.record.data.<slug>}}, output_variable=summary → record.update setting the summary back via {{vars.summary}}.
  - Agent-generated content: a manual button → agent.invoke (agent_id from list_agents, message=the brief) → record.create using {{steps.<id>.output.text}}. Use agent.invoke (not ai.complete) when a CONFIGURED agent should do the work — it brings that agent's model, instructions, knowledge base and tools; ai.complete is only a raw prompt.
- `script.run` runs sandboxed JavaScript (isolated QuickJS — no network, filesystem or host access). It is THE escape hatch when the expression catalog can't express what's needed: looping, parsing/reshaping arrays or objects, multi-step or multi-branch computation, anything you'd otherwise be tempted to write as a non-existent function. Its `input` map is resolved against the workflow context and passed as the `input` argument; the script uses a top-level `return` for its output (a scalar is wrapped as `{value}`, reachable via {{steps.<id>.output.value}}). Do NOT use it for simple math/comparisons (use formula/branch expressions) or for DB access (use record.* steps) — the sandbox cannot reach the database.
- JS runs ONLY inside `script.run` — it cannot be inlined into a `formula` field, a `value_expression`, or an action value (expression-only). So when a computed value that needs JS must be DISPLAYED like a formula: workflow (trigger record.created/record.updated) → `script.run` computes it → `record.update` writes the result into a normal (non-formula) field → that field is shown like any other.
- Use `branch` for conditional steps. A condition is a full boolean expression: "{{trigger.record.data.estado}} == \"activo\"", "{{vars.total}} > 1000". Operators: `== != < <= > >=`, `and or not` (or `&& || !`), ternary. Use `~`/concat for string building, not `+`.
TXT,

        'derived_fields' => <<<'TXT'
DERIVED FIELDS (formula / lookup / rollup):
- They are READ-ONLY (`readonly: true` is mandatory) — value is computed at query time, never stored. They show in tables, stats and charts like any other field.
- Pick formula when the value comes from THIS row only. Pick lookup when the value lives on the OTHER side of a many_to_one relation (e.g. show client.company_name on every order). Pick rollup to count/sum/avg the CHILDREN of a one_to_many relation (e.g. orders_count or revenue_sum on a customer).
- A rollup requires the parent's one_to_many relation field to have `inverse_field_id` set — the many_to_one field on the child that points back. Without it the engine can't find the children and the rollup is null.
- See the `expressions` topic for the formula syntax and function catalog.

SYSTEM FIELDS (every object has them automatically):
- Every object exposes three implicit fields you can reference without declaring them: `id` (the record's primary id, string), `sys_created_at` (insert time) and `sys_updated_at` (last modification). Backfilled, work on all existing records, zero setup.
- `id` is the get-by-id handle: filter `{op:"eq", field_id:"id", value_expression:"…"}` (or `in` with an array) fetches a specific record — including inside a workflow `record.query` step to read the one record a relation points at, e.g. `value_expression:"{{trigger.record.data.<relation_slug>}}"`.
- ALWAYS prefer these over inventing a manual datetime field for "X created in the last N days", "newest records", "activity over time", heatmaps, timelines, sparklines of growth. Good: `x_field_id: "sys_created_at"` or a filter `{op:"gte", field_id:"sys_created_at", value_expression:"..."}`.
- READ-ONLY — do NOT use them in form blocks or set them via action.values. Valid in: table.columns, table sort, filter conditions, sparkline.x_field_id, heatmap.date_field_id, calendar.date_field_id, timeline.date_field_id, gauge/stat (with count aggregation only — they're datetime, so sum/avg make no sense).
TXT,

        'expressions' => <<<'TXT'
EXPRESSIONS (formula fields, value_expression, branch/filter conditions):
- Formula expressions are REAL expressions inside `{{ … }}`, evaluated by a sandboxed engine. Reference this row's fields by their bare slug (no prefix): `{{monto * 1.16}}`, `{{cantidad * precio_unitario}}`, `{{activo ? "Sí" : "No"}}`, `{{total > 1000}}`.
- Operators: arithmetic `+ - * / %`, comparison `== != < <= > >=`, logic `and or not` (or `&& || !`), ternary `cond ? a : b`. IMPORTANT: `+` is NUMERIC addition — for STRING concatenation use `~` or `concat(...)`, e.g. `{{nombre ~ " " ~ apellido}}`.
- Functions available (EXHAUSTIVE): now, today, upper, lower, concat, round, abs, floor, ceil, count, length, default, random, days_ago, months_ago, start_of_week, start_of_month, start_of_year. `random()` → float in [0,1); `random(min,max)` → integer in [min,max]; `random(array)` → random element. For a random whole number prefer `{{random(form.min, form.max)}}`.
- DATE HELPERS (return a YYYY-MM-DD date, UTC) — for period filters and previous-period `compare` queries WITHOUT hand-computing dates. The arg is "periods back" (0 = current): `days_ago(n)` (n days before today), `months_ago(n)`, `start_of_week(offset)` (Monday), `start_of_month(offset)` (1st of the month offset months back), `start_of_year(offset)`. PERIOD-VS-PERIOD PATTERN (half-open ranges, no end-date ambiguity): this month = filter `{op:"gte", field_id:"sys_created_at", value_expression:"{{start_of_month()}}"}`; previous month (the stat's `compare` query) = `{op:"and", conditions:[{op:"gte", field_id:"sys_created_at", value_expression:"{{start_of_month(1)}}"}, {op:"lt", field_id:"sys_created_at", value_expression:"{{start_of_month()}}"}]}`. Rolling 30 days vs the prior 30: current `{gte sys_created_at days_ago(30)}`; compare `{and: gte days_ago(60), lt days_ago(30)}`. This is what makes a stat/metric_grid trend chip (and a computed insight) actually current.
- Calling ANY other function — or JS-style `Math.random()`, `Date.now()`, method calls like `x.toFixed()` — is rejected at save. No string methods, regex helpers or date math beyond now/today; compose from these. If a value genuinely needs logic these can't express (loops, parsing, multi-step transforms), do NOT invent a function — compute it with a `script.run` workflow step (see `workflows`) that writes the result into a normal field, then reference that field.
- Template interpolation mixes literal text with values: `{{nombre}} {{upper(apellido)}}` → "Ana LOPEZ". Set `return_type` to match (number for arithmetic, string for text, boolean for comparisons).
TXT,

        'design' => <<<'TXT'
VISUAL / THEME:
- GLOBAL theme: set `settings.theme` to "light" or "dark" via propose_change (path "/settings/theme"). Default "light" (clean white page), right for most sites; only "dark" if asked.
- NAV LAYOUT: `settings.navigation_layout` is "top" (default horizontal header) or "sidebar" (left rail). Switch to "sidebar" when the app has many pages or needs GROUPED/NESTED menus. The sidebar renders the manifest's `navigation` (root: {items:[{id,label,icon?,page_id?,children?[]}]}) — a nested item with `children` becomes a collapsible group; an item with `page_id` is a link. Without a `navigation`, both layouts just list the pages. So for a grouped menu: author `navigation` with nested items AND set navigation_layout="sidebar". Item `icon` accepts a named icon or emoji (see `icons`).
- PER-BLOCK style: every block accepts an optional `style` object `{padding: none|sm|md|lg, margin: none|sm|md|lg, background:"#RRGGBB", color:"#RRGGBB", max_width: sm|md|lg|full}`. Use `style.background` + `style.padding="lg"` to make a `container` a coloured section. CONTRAST IS AUTOMATIC: when you set `background`, the runtime auto-picks readable text colour — do NOT set `color` yourself and do NOT put a `background` on the inner heading/markdown. Set `max_width:"md"` on text sections so lines stay readable and centre on wide screens.
- To recolor a `single_select` field's options, replace each `options[i].color` hex (the chip shown in tables/badges).

CONTENT & WEBSITES (landing/marketing pages, "make it nice"):
- A CONTENT site (website, landing page, portfolio, "página bonita") is visual composition, not CRUD. Do NOT settle for heading + text + spacer — that reads as a plain document. Build rich, generous pages.
- FIRST set site identity in settings: an `accent` brand colour, a `font`, a `brand` {name, cta?} (renders the site header) + a `footer`. Then the home page: `hero` (title + subtitle + stock background_image + cta) → optionally a `stat_band` of headline numbers → a `feature_grid` (3-4 benefit cards with emoji icons) → alternating SECTIONS, each a `container` with `style.padding="lg"`, `style.full_bleed=true`, a `style.background` or `style.gradient`, `style.max_width="md"`, holding a `heading` + `markdown`/`text` and often a `split_view` (image one side, copy the other) → a `testimonials` and/or `pricing` and/or `faq` section where relevant → finish with a `cta` block (bold gradient/background, full_bleed). Vary section backgrounds for rhythm. Use the dedicated blocks (`feature_grid`, `stat_band`, `testimonials`, `pricing`, `faq`) instead of faking them. Buttons/accents auto-use the `accent` colour.
- ALWAYS include imagery — pages without images don't look "bonito". Put stock URLs in `image.src` / `hero.background_image` using `https://picsum.photos/seed/<word>/<w>/<h>` with a DIFFERENT `<word>` per image (e.g. .../seed/solar/1200/800) so each photo is distinct and stable. Do NOT use `source.unsplash.com` (retired, broken images). Always write a descriptive `alt`.
- Be generous, finish in one turn: a real landing page is ~8-20 blocks across hero, 3-5 sections, and a CTA — not 4 blocks. Call `list_available_components` to recall the catalog.
- Rich visual builds come out far better on a stronger model. If a big creative/website request looks shallow, you MAY tell the user (in their language) "esto queda mucho mejor con un modelo más potente — puedes cambiarlo en el selector de modelo del builder", then proceed with your best effort.

DASHBOARDS & REPORTS:
- DATE FILTER: if the object has a date/datetime field (or you chart over sys_created_at), ALWAYS put a `filter_bar` with ONE `date_range` control (default:"30d") right under the header. Give every time-relevant block (KPIs, charts, tables) a data_source.filter of {op:"gte", field_id:<that date field>, value_expression:"{{range_start(default(params.<param>, '30d'))}}"} so the presets (Hoy/7d/30d/90d/Año/Todo) re-scope the whole board at once; 'all' clears it. Combine with any other filters via an {op:"and", conditions:[…]} group. This is expected on any dated dashboard — a board with no way to change the window feels unfinished.
- HEADER: a dashboard MAY open with a COMPACT, LEFT-ALIGNED `hero` as a slim brand title band — `align:"left"`, a small `min_height` (~110-140), and a `style.gradient` in the org's accent ramp (e.g. {from: accent-900 hex, to: accent-600 hex, direction:"to-br"} from get_organization_brand). Keep it SMALL: it names the report without stealing space or prominence from the data. NEVER a tall landing-style hero (min_height 400+, centred) on a dashboard — that pushes the KPIs below the fold. A plain `heading` is also fine; reserve the big centred hero for marketing/landing pages.
- A DASHBOARD REQUEST IS NOT AN APP BUILD: "analiza X" / "crea un dashboard de X" / "reporte de X" wants a dashboard PAGE, not a full app. Do NOT `scaffold_app` (CRUD pages, forms, master-detail, POS) for it. Ensure the data exists first (use existing objects, or pull from a connected source — `sample_mcp_tool` / `sample_endpoint` — and seed the real rows), then build ONLY the dashboard page via the flow below. Reserve scaffold_app for "build an app to manage/track X" where data entry is the point.
- BUILD IT WITH `add_dashboard_page` (one compiled call): declare the CONTENT — kpis, varied charts, insights — and the SERVER compiles the professional layout (KPI band first, balanced rows with col_span weights, the date-range filter wired into every block, the compact brand hero) and runs the dashboard lints before applying; if it returns errors, fix the CONTENT and re-call. Hand-building block-by-block is the fallback for exotic layouts only — then `plan_dashboard` is MANDATORY first (purpose + rows top→bottom, chart_type + col_span per block; treat `issues` like validation errors, re-call until ok:true, build exactly that plan).
- FIRST call `list_dashboard_blueprints` (pick the closest sector — support, sales_crm, ecommerce_retail, saas_subscriptions, or general) to get the EXPERT KPI set, the right charts and the conclusions for that domain. THEN call `profile_object` on the object(s) you'll chart — it classifies each field (measure/temporal/categorical/identifier) and reports cardinality, ranges and % nulls, so you map the blueprint's KPIs to fields the data actually supports and pick chart types that fit (a 4-value status → donut; a 500-value field → top-N hbar, never a pie). This is what makes a dashboard read as specialised instead of generic — don't reason the KPIs from scratch.
- Shape: a top KPI row (`metric_grid`, ideally with `compare` + `delta_good` so each card shows its trend; a headline card can also carry an inline `spark` — a small trend line beside the value plotting the daily history behind the number — plus a `compare_label` like "vs período anterior") → a grid of `chart`s (varied types: bar/line/area for trends, pie/donut for share, hbar for rankings, radar for multi-metric profiles — with series_field_id to overlay 2-3 entities, scatter for correlations, treemap for part-to-whole segmentation, sankey for flows between two categories, box for distributions per category, pareto for concentration/root-cause rankings (descending bars + cumulative-% line, 80% reference), plus funnel/heatmap/timeline/gauge where they fit) → and crucially `insight` cards where YOU state conclusions, recommendations and risks read from the data (variant=conclusion/recommendation/risk/positive). A report is not just charts — pair numbers with written insight cards. Make the key insight cards LIVE: give them a `compute` (same shape as a stat — query+aggregation+optional compare/delta_good) so the figure and its trend chip are computed at runtime from current data, not a number you hard-coded that goes stale (e.g. a "conclusion" card titled "Resolución vs período anterior" computing median resolution time with a previous-period compare). Put charts side by side with `container` direction=row or `split_view`.
- NO GAPS — balance each row. A `direction=row` container lays its children out as EQUAL columns that fill the width and stretch to a shared height, and each card fills that height, so the runtime already removes most empty space. Your job is to keep rows BALANCED so nothing looks stretched-thin: put blocks of SIMILAR natural height side by side (a donut next to a donut, a top-N `hbar` next to another list of comparable length, a tall `chart` next to a tall `chart`). Do NOT pair a very short block with a very tall one (e.g. a 3-row hbar beside a 12-row list, or a single `stat` beside a full chart). When a block is inherently TALL and has no comparable partner, give it its OWN full-width row (a lone `container` child, or just place it directly on the page) rather than pairing it with something short. Aim for 2 (sometimes 3) children per row; more than 3 charts in one row get cramped. A KPI `metric_grid` is its own full-width row — never put it beside a chart.
- WEIGHTED COLUMNS — when two blocks in a row deserve UNEQUAL width, set `style.col_span` (1-12) on each child; the row splits its width by the weights and keeps both the same height. The classic case: a wide time-series `line` chart beside a narrow companion (a `donut` share, a small stat) → give the line `col_span:7` and the donut `col_span:3` for a 70/30 split (a treemap/table that needs room → 8/4). Set col_span on EVERY child of that row (or none — unset = equal columns). It only applies inside a `direction:"row"` container.
- MASONRY — use it when balancing rows isn't natural. When you have MANY (4+) INDEPENDENT cards of genuinely DIFFERENT heights (a wall of charts/insights where no clean same-height pairing exists), put them in ONE `container` with `direction:"masonry"` instead of hand-pairing rows: it packs them at their natural height into responsive columns and fills the vertical gaps automatically — the surest way to a gap-free board. Its trade-off is that reading order becomes column-major (top-to-bottom of column 1, then column 2), so use it ONLY when the cards are independent and order doesn't carry meaning. Do NOT use masonry for an ordered narrative (KPIs → trend → breakdown → conclusion) or when a specific block must lead — use `direction:"row"`/`"column"` there. Never put a full-width KPI `metric_grid` inside a masonry; keep it as its own row above the wall.

VISUALISATION BLOCKS:
- CHART SHAPE — get it right the FIRST time (these are the common misfires): a `chart` takes `chart_type`, `data_source` ({object_id, filter?, sort?, limit?} — NOTHING else; the breakdown does NOT go here), `aggregation`, and — for the breakdown — `group_by_field_id` ON THE CHART (not in data_source). Charts aggregate with count|sum|avg|min|max ONLY: for sum/avg/min/max also set `y_field_id` (the numeric field); count needs none. median/p90/p95 are NOT chart aggregations — put those in a `stat` / `metric_grid` KPI instead. `data_source.sort` is an ARRAY: [{field_id, direction:"asc"|"desc"}]. A date/datetime X → set `bucket` (see TIME-AXIS below).
- Use `chart` for trends/distributions, `kanban` for status-driven workflows (group_by must be a single_select), `calendar` for date-keyed records. All three need a working query data_source — call `simulate_query` first if unsure data exists.
- MATCH THE BLOCK TO THE DATA SHAPE — the dedicated blocks exist so the right data gets the right form, use them instead of forcing everything into `chart`: lat/lng number fields → `map` (interactive markers); a start AND end date per record → `gantt` (schedules, projects, bookings); date + title, chronological reading → `timeline` (activity logs, comms history); daily activity density → `heatmap` (GitHub-style); a categorical/text field's frequency → `word_cloud`; records that deserve visual scanning (image/title) → `card_grid`; one value against a goal/quota → `gauge` (or `progress`, which stacks better in columns); a compact trend inside a stat context → `sparkline`; staged conversion → `funnel`; TWO dimensions at once (revenue by region AND month) → `pivot`, a matrix with an aggregate per cell — and for RETENTION set the pivot's mode:"cohort", which turns the columns into the offset from each row's own start (Mes 0, Mes 1…) and each cell into the % of that cohort still present, so cohorts born in different months become comparable (rows = signup date bucketed by month, columns = activity date with column_bucket month, aggregation = distinct_count over the customer field). These all count toward chart VARIETY in plan_dashboard — a dashboard that mixes a map or gantt where the data fits reads far more professional than five bar charts.
- COMBO / DUAL-AXIS charts: to overlay measures of different types or scales, set the chart's `series` array (each {type: bar|line|area, aggregation, field_id?, axis: left|right, label?, color?}) over a shared X (group_by_field_id, or x_field_id). Put a second metric on `axis:"right"` when its unit/scale differs from the first — the classic pattern is revenue (bar, left axis) + conversion rate or growth % (line, right axis). When `series` is present it drives the whole chart; still set a top-level chart_type ("bar") + aggregation to satisfy the schema (they're ignored).
- TIME-AXIS charts: any chart (combo or single) whose X / group_by is a date/datetime — including the system field sys_created_at — should set `bucket` (day|week|month|quarter|year). The runtime then truncates each value to that period, shows one point per period and sorts them chronologically (without `bucket` a date X defaults to daily buckets). So "revenue by month" = a chart with group_by_field_id=sys_created_at, bucket="month", aggregation=sum on the amount field.
TXT,

        'verification' => <<<'TXT'
VERIFICATION (on demand — use judgment, skip for trivial edits):
- Before proposing a TABLE block over an existing object, call `simulate_query` with the block's data_source. If count is 0 when the user expected results, or it errors, fix the filter before propose_change.
- Before a STAT block with sum/avg/min/max, call `simulate_query` with the same aggregation and field_id. Verify aggregation_value is sensible (not null, right magnitude).
- When the user references existing data ("filter active clients", "sum last month's revenue") and you're unsure which field captures it, call `inspect_records` first to see what keys + value shapes exist before guessing field slugs.
- DEMO / SEED DATA ("agrega N registros", "llena con datos de prueba", "/seed …"): use the `seed_records` tool to actually create rows — do NOT say "I can't insert records" or offer manual JSON. Before calling, read the object's field slugs + single_select option slugs (you pass option SLUGS, not display names). For relation fields, call `inspect_records` on the target object for real record ids. Cap 100 records/call — chain for more. Report the `created` count and any per-record errors.
- SKIP verification for rename-only changes, layout tweaks, or pure structural patches that don't query data.
TXT,

        'data' => <<<'TXT'
DATA & QUERYING (one shared query engine — RLS + role policies apply on every read):
- Every object's records go through the same engine whether read by a page block, the app's embedded agent, or an external MCP client. Tenant isolation and the manifest `permissions` (row_filter / field_restrictions) are always enforced — reads never bypass them.
- WHAT A BLOCK data_source MAY CONTAIN (authoring): `filter` (logical and/or/not over leaf ops eq, neq, gt, gte, lt, lte, in, not_in, contains, starts_with, ends_with, between, is_null, is_not_null referencing field_ids — PLUS `{op:"related", field_id:"<a relation field>", condition:{…filter on the related object…}}` to keep only rows whose related record(s) match, belongs_to or has_many), a `search` string (case-insensitive across the object's text fields), `sort` [{field_id, direction}], and limit/offset. stat/gauge/progress/metric_grid take an `aggregation` + field_id; chart/kanban/calendar group by a field. SCALAR KPI blocks (stat, metric_grid, gauge, progress) support the FULL set: count, sum, avg, min, max, distinct_count (unique values of ANY field — unique customers/SKUs), and median/p90/p95 (percentiles of a numeric field — median deal size, p95 resolution time). Charts and funnel support count|sum|avg|min|max only (they aggregate client-side), so put a distinct_count/percentile figure in a stat/metric_grid, not a chart. Verify with `simulate_query` before proposing (see `verification`).
- AUTHORING LIMIT (avoids a validation failure): inline relation EXPANSION (`expand`) and grouped/bucketed AGGREGATION query-args are RUNTIME-only (below) — they have no key in a block data_source. To DISPLAY related data in a page, don't expand: use a `lookup` field to pull a related value onto this object, a `rollup` to count/sum children (see `derived_fields`), or a `related_list` block to list a record's children. For grouped/time-bucketed views, author a `chart` block (it groups) or a `stat` (it aggregates) — not a query group_by.
- RUNTIME QUERY POWERS — available to the app's embedded AGENT (query_object / aggregate_object / describe_capabilities) and to external MCP clients (query_records / aggregate_records / describe_app_data), NOT for manifest authoring:
  - describe_app_data / describe_capabilities — the big picture: objects, fields, live record counts, the relation graph. Read first to learn what's queryable.
  - query_records / query_object — filter (including {op:"related", field_id, condition} to traverse a belongs_to/has_many in one query), `search` (case-insensitive across text fields), `sort`, `expand` (resolve relations inline: belongs_to → the related record; has_many → a capped child list with the true count), and total/has_more paging.
  - aggregate_records / aggregate_object — count/sum/avg/min/max/distinct_count/median/p90/p95, optionally `group_by` a field with a date `bucket` (day|week|month|quarter|year) → "sum revenue by month" in one call, and `group_by_2` for a PIVOT/matrix → "revenue by region AND month" returning [{group, group2, value}].
- Bottom line: blocks CAN now filter across relations (`related`) and free-text `search`; for related-data DISPLAY and grouped views use lookup/rollup/related_list + chart/stat. `expand` and query `group_by`/`bucket` stay runtime-only — the embedded agent already has them at query time.
TXT,

        'visual_review' => <<<'TXT'
VISUAL REVIEW (the user attached a screenshot of the rendered runtime):
- Look carefully and report what you SEE — empty tables, overflow, clashing colours, broken layout, missing labels, charts with no data, awkward spacing, blocks rendering "—" everywhere. Be specific ("the chart on the right has no bars because field_id points at a non-numeric field"), not vague. Keep it SHORT: one concrete clause per issue, no narration of how you inspected it.
- If everything is genuinely fine, say so in one short sentence and STOP. Do not invent improvements. When you DO fix something, confirm in one concrete clause per fix.
- If you see fixable issues, propose the change with propose_change IN THE SAME TURN. Don't ask "should I fix it?" — just fix it (auto-applied, user can undo).
- CRITICAL anti-pattern: describing a defect then declaring "todo se ve bien" without emitting a patch. If your description names a concrete issue, you MUST follow with propose_change for it. The only excuse is if the issue is OUT of the manifest's reach (a hard-coded styling decision in the runtime renderer) — then say so explicitly.
- Prefer the smallest patch that addresses the symptom. A "buttons look wrong" screenshot gets a button fix, not a layout rewrite.
- HARD SCOPE LIMIT: only modify what's ALREADY in the manifest. NEVER add new objects/fields/pages/modals/workflows/features the user didn't already ask for. A "thin" page (only a heading, no form yet) is NOT a bug — the user just hasn't asked for those parts. The describe=fix rule applies to BUGS in existing structure, not features you imagine. If in doubt, treat visual review as read-only and ASK before adding.
- Lean on the catalog tools (read_manifest, simulate_query, inspect_records) BEFORE proposing fixes — a broken-looking chart might be bad data, not block config.
TXT,

        'connected_objects' => <<<'TXT'
CONNECTING EXTERNAL SYSTEMS (integrations):
- When the user needs the app to talk to an external system ("conéctate a HubSpot", "datos de Stripe", "usa nuestra API de X"), set up the connection in this conversation:
  - Call `discover_integration` with the API's URL to auto-detect OAuth2. If discoverable:true, pass its `cache_key` to `create_integration`. If discoverable:false, ask the user for the base URL and the auth kind (api_key / bearer) and call `create_integration` directly.
  - `create_integration` makes a DRAFT connection — NOT usable until the user authorizes it (OAuth consent, or entering a secret in a secure field). Tell the user (in their language) they need to authorize it.
  - NEVER ask the user to paste a secret/token/password into the chat, and NEVER put secrets in tool arguments. Secrets are captured through a secure field.
  - After authorization, call `test_connection` (with a lightweight test_path when you know one) and only then report it working. If the test fails, say so plainly.
- MCP CONNECTIONS (is_mcp): these expose their own tools over the protocol — `sample_endpoint` is REST-only and 405s against them. Use `sample_mcp_tool`: call it with just the integration_id to LIST the server's tools, then call the right one (arguments matching its input_schema) to see the SHAPE of the real records. FOR A DASHBOARD, PREFER A LIVE CONNECTED OBJECT over copying rows: model the object from that shape and give it a `source` {type:"connected", integration_id, operations:{list:{mcp_tool:"<the list tool>", arguments?:{…e.g. a page size…}, collection_path?:"…"}}, id_path, field_map} (see the `connected_objects` topic). It reads the source LIVE at render time — always current, no seeding, no per-row create_record loop (which is slow and times out). Only `seed_records` the sampled rows when you deliberately want a frozen snapshot instead of a live read. NEVER invent demo data or `generate_demo_data` when a live MCP source is connected. If the call fails (auth/endpoint), report the exact error and that the connection needs authorizing — don't silently substitute placeholder data. (A live connected object reads AS THE VIEWING USER — a per-user OAuth MCP like YuhuGo resolves each viewer's own token, so every viewer must have authorized the connection; static/service-auth MCPs read with the stored credentials for everyone.)
- A "connected object" reads LIVE from a REST OR MCP connection instead of the internal records store:
  - REST: call `sample_endpoint` with the integration_id and a list/read path (e.g. "/crm/v3/objects/deals?limit=3", collection_path "results") to fetch a REAL sample, then propose (via propose_change) an object whose `source` is {type:"connected", integration_id, operations:{list:{method,path,collection_path}}, id_path, field_map:[{field_id, external_path}]}.
  - MCP: call `sample_mcp_tool` (integration_id, then the list tool) to see the shape, then propose an object whose `source` is {type:"connected", integration_id, operations:{list:{mcp_tool:"<tool>", arguments?:{…}, collection_path?:"…"}}, id_path, field_map:[{field_id, external_path}]} — NO method/path for MCP; the tool's JSON rows feed field_map/id_path. The live read runs AS THE VIEWING USER: a per-user OAuth MCP resolves each viewer's own token (so every viewer must have authorized the connection), while a static/service-auth MCP reads with the stored credentials for everyone.
  - Either way: map each field to a real key from the sample (dot paths ok); leave unmapped fields out (render null). Connected objects are READ-ONLY for now — include only the list (and optional read) operation.
  - The sample call IS the verification that the mapping is real; confirm the mapping with the user before proposing if unsure. Data stays in the external system (passthrough) — you are NOT copying it in.
  - A connected object can back a DASHBOARD: tables/charts/card_grid read its rows, AND the KPI blocks (stat, metric_grid, gauge, progress, funnel) now aggregate it live in-memory — count/distinct_count/sum/avg/min/max/median/p90/p95 all work over a connected object, same as an internal one. Caveat: aggregation runs over the rows the external list returns (filters/limits are pushed down only where the source maps them), so for very large external datasets prefer a tighter filter or ingest into internal records (a poll workflow) for exact totals.
TXT,

        'palette' => <<<'TXT'
COLOUR PALETTE (richer UIs from the brand accent, kept executive):
- The runtime DERIVES a full professional palette from the app's effective accent (the app's `settings.accent`, else the org Brandbook accent, else the platform blue) and exposes it as CSS variables on every app surface — you don't generate or store it, just USE the variables:
  - `var(--sp-accent)` — the brand accent (primary actions, links).
  - `var(--sp-accent-50 … --sp-accent-900)` — a tint→shade ramp. 50/100 = very light tints for section/card backgrounds and chips; 700–900 = deep shades for text-on-light or borders.
  - `var(--sp-accent-soft)` — the lightest tint, a one-call section background.
  - `var(--sp-accent-contrast)` — readable text colour to put ON the accent.
  - `var(--sp-chart-1 … --sp-chart-6)` — a cohesive categorical series. Charts already use it automatically; reference it if you colour anything series-like yourself.
- Call `generate_palette` (optionally with a base hex) to SEE the concrete hexes + variable names — useful when you want to reason about specific colours. But prefer the CSS variables over hard-coded hexes in block `style` / `custom_css`, so the palette tracks whatever accent the org/app picks.
- HOW TO USE IT TASTEFULLY (executive, not loud): tint SECTIONS not whole pages — e.g. a dashboard KPI row or a feature section with `style.background` = a 50/100 tint; primary buttons on `--sp-accent` with `--sp-accent-contrast` text; alternate plain and soft-tinted sections for rhythm. Don't paint large areas in the full-strength accent, don't use more than ~2 palette steps on one screen, and keep body text on the theme's default colours. Status colours (success/danger) stay green/red — the brand palette is for brand accents, not semantics.
- Example (soft section via custom_css): [data-block-type="metric_grid"] .card { background: var(--sp-accent-50); border-color: var(--sp-accent-100); }
TXT,

        'icons' => <<<'TXT'
ICONS (use them liberally for clear, scannable UIs):
- Almost every chrome element takes an `icon`: button.icon, feature_grid items, stat/metric_grid items, insight, flow steps, table action columns (columns[type=action].icon), page.icon (shown in nav), nav items, card_grid items. Add icons by default — a button labelled "Add" reads better with a `plus`, a revenue stat with a `dollar`, a delete action with a `trash`.
- TWO kinds of value, both valid:
  - a NAMED icon (preferred for UI chrome): a kebab-case Lucide name like `shopping-cart`, `user`, `calendar`, `trending-up`, `trash`. Renders as a crisp vector icon that inherits the surrounding text/accent colour and sizes to the context. ANY real Lucide icon works (lucide.dev/icons), not just a fixed list — call `list_available_icons` for a curated shortlist if unsure, but a sensible guess (e.g. `chart-column`, `circle-alert`) renders fine too; an unknown name silently falls back to plain text.
  - an EMOJI (fine for playful/marketing accents): "🚀", "📦". Renders as-is.
- Guidance: prefer NAMED icons for app chrome (buttons, table actions, stats, nav) — they stay monochrome and on-brand. Reach for emoji on landing/feature sections when you want colour and personality. Don't mix both styles within one toolbar/row. Match the icon to meaning (a `check` for confirm, `x`/`trash` for destructive, `pencil`/`edit` to edit).
- Example action column: {"type":"action","label":"Edit","icon":"pencil","on_click":[…]} ; KPI stat: {"type":"stat","label":"Revenue","icon":"dollar",…} ; feature card: {"icon":"shield-check","title":"Secure",…}.
TXT,

        'custom_css' => <<<'TXT'
CUSTOM CSS (settings.custom_css — the scoped escape hatch):
- WHAT IT IS: raw CSS for fine visual touches the structured options can't express (hover/transition effects, ::before/::after accents, gradients on a specific block, custom shadows, a bespoke card look). Set it at `settings.custom_css` via propose_change (path "/settings/custom_css").
- ISOLATION (automatic): the runtime WRAPS your CSS in `.sp-app-surface { … }` using CSS nesting, so every selector only matches INSIDE this app — it can never touch the Sapiensly platform chrome or another app. Write PLAIN selectors; do NOT prefix them with `.sp-app-surface` yourself. To style the surface itself, write bare declarations or `& { … }` at the top level.
- USE THE LADDER (don't reach for CSS first): 1) `settings.accent`/`font`/`theme` for global brand; 2) a block's `style` object (padding/margin/background/color/max_width/full_bleed/gradient) for per-block spacing & colour; 3) custom_css ONLY for what those can't do. The org Brandbook + accent should carry most of the look.
- TARGETING (stable hooks the runtime emits): every block renders with `data-block-type="<type>"` and `data-block-id="<id>"`. Prefer these:
  - by type: `[data-block-type="table"] { … }`, `[data-block-type="stat"] { … }`
  - one specific block: `[data-block-id="blk_…"] { … }`
  - element-level inside the app: `h2 { … }`, `button { … }`, `a { … }`.
- USE THE TOKENS, don't hardcode brand colours: `var(--sp-accent)` (the brand accent), `var(--sp-accent-blue)` / `var(--sp-accent-blue-hover)` (primary button). This keeps custom CSS in sync with the brand/accent the user picks.
- DON'Ts (some are rejected at save): no `@import`, no `<style>`/`<script>`, no `javascript:` URLs, no `expression(...)`. Don't `display:none` the header/nav (it holds the "exit to Sapiensly" widget). Avoid `position: fixed` overlays that cover the page. Keep text vs background contrast readable — if you set a background, set a matching text colour.
- EXAMPLE (rounded, lifted cards with an accent top border + smooth hover):
  [data-block-type="card_grid"] .card, [data-block-id="blk_kpis"] {
    border-radius: 14px;
    border-top: 3px solid var(--sp-accent);
    box-shadow: 0 1px 2px rgba(0,0,0,.05), 0 8px 24px rgba(0,0,0,.06);
    transition: transform .15s ease, box-shadow .15s ease;
  }
  [data-block-type="card_grid"] .card:hover { transform: translateY(-2px); }
TXT,

        'permissions' => <<<'TXT'
PERMISSIONS & ACCESS (roles, policies, access mode — ENFORCED at runtime):
- The manifest's `permissions` block is now ENFORCED server-side on EVERY surface: page access, block visibility, record reads, record writes, AND the app's embedded agent. (It used to be validated-but-ignored — that is no longer true.) Authoring it wrong locks users out or leaks data, so be deliberate. An app with NO object/page policies stays open-within-visibility (the zero-config default) — add policies to RESTRICT, not to grant.
- ADMINS BYPASS EVERYTHING: the app owner, organization owners, and sysadmins skip all policies. Never write a policy just to "let the admin in" — they already have full access.
- ROLES (`permissions.roles[]` = `{id, slug, name, is_default}`): the `slug` is the stable handle that policies, visibility rules and assignments reference (it survives manifest edits; the regenerated `id` does not — but object/page POLICIES reference the role by `id`). EXACTLY ONE role must have `is_default:true` when there are 2+ roles — zero or several is rejected by validation. A single-role app needs no flag (that role is the implicit default). Scaffolded apps ship `admin` (not default) + `user` (default).
- ACCESS MODE (`permissions.access_mode`, enum, default `open`):
  - `open`: every org member who can see the app gets the DEFAULT role; explicit assignments only ELEVATE (e.g. to admin).
  - `allowlist`: ONLY members with an explicit role assignment may enter — everyone else is denied (403). Use for sensitive/internal apps.
- OBJECT POLICIES (`permissions.object_policies[]` = `{object_id, role_id, actions, row_filter?, field_restrictions?}`, one entry per object×role):
  - `actions`: subset of `["create","read","update","delete"]` the role may perform. Omit create/update/delete to make a role read-only. An object with NO policy at all is fully open within visibility.
  - `row_filter`: a filter_expression (SAME shape as a data_source filter — see `expressions`) that scopes BOTH reads and writes for that role. Classic "own rows only": `{"op":"eq","field_id":"fld_owner","value_expression":"{{current_user.id}}"}`. A record outside the filter is invisible AND cannot be updated/deleted (resolves to not-found — this closes the escalation hole).
  - `field_restrictions.hidden`: FIELD IDS stripped from every read for that role. `field_restrictions.readonly`: FIELD IDS that role may not write (rejected). Both are arrays of field IDs, NOT slugs.
  - Multiple policies for one role combine most-permissively: actions union, row_filters OR, readonly union, hidden intersection.
- PAGE POLICIES (`permissions.page_policies[]` = `{page_id, role_id, can_view}`): when a page has ANY page_policy, only roles granted `can_view:true` may open it (hidden from nav + 403 on direct visit). A page with no policy is visible to all (still subject to its own visibility rule).
- VISIBILITY RULES (optional `visibility` object on a page, block, or nav item = `{roles:[slug,...], expression?}`): the element shows ONLY to users holding one of the listed role SLUGS. Blocks failing the rule are stripped server-side BEFORE their data is resolved — perfect for an admin-only section inside an otherwise shared page. (Reference roles here by SLUG; object/page policies reference roles by ID.)
- AGENT PARITY: the embedded runtime agent acts AS the requesting user and can never exceed their role — its tools omit objects the role can't reach, its reads honour row_filter + hidden fields, and proposed writes are re-authorized at execution. You do NOT configure this separately; authoring the policies above governs the agent automatically.
- ASSIGNING ROLES TO PEOPLE is RUNTIME DATA, not the manifest. The manifest only DEFINES roles + policies; it never lists individual users. Who-gets-which-role is managed in the builder's "Access" panel (or the access endpoints), stored per (app, user). So your job when "add roles/permissions" is asked: author the roles, access_mode, and policies — then tell the user to assign members in the Access panel.
- WORKED SNIPPET (admin full; member read-only on their OWN rows with an internal field hidden; an admin-only page):
(ids follow the same `<prefix>_<8-60 token>` rule as everywhere else — short labels like "rol_admin" are too short and fail validation)
{
  "permissions": {
    "access_mode": "open",
    "roles": [
      { "id": "rol_adminrole01", "slug": "admin", "name": "Admin", "is_default": false },
      { "id": "rol_memberrole01", "slug": "member", "name": "Member", "is_default": true }
    ],
    "object_policies": [
      { "object_id": "obj_ticketsobject", "role_id": "rol_adminrole01", "actions": ["create","read","update","delete"] },
      { "object_id": "obj_ticketsobject", "role_id": "rol_memberrole01", "actions": ["read"],
        "row_filter": { "op": "eq", "field_id": "fld_ownerfield01", "value_expression": "{{current_user.id}}" },
        "field_restrictions": { "hidden": ["fld_internalnotes01"] } }
    ],
    "page_policies": [
      { "page_id": "pag_adminpage01", "role_id": "rol_adminrole01", "can_view": true }
    ]
  }
}
TXT,

        'example' => <<<'TXT'
WORKED EXAMPLE — a COMPLETE valid "Mini CRM" manifest (this exact thing passes validation). The placeholder ids are valid; generate your own `<prefix>_<token>` ids — a 2-5 lowercase-letter prefix, an underscore, then 8-60 chars of [a-z0-9_] (a lowercased ULID is one easy choice, but ANY opaque lowercase token in that range is accepted; it does NOT need to be exactly 26 chars or valid base32). Pattern-match this SHAPE instead of reasoning the schema from scratch. Note the EXACT keys that trip people up: `schema_version` is a string "1.0.0" (not a number); top level REQUIRES id, version and permissions; single_select options use `value`+`label` (NOT slug); a `heading` block uses `content` (not text); a `page` requires `path` (starts with "/"). There is no `email` or `text` field type — use `string`.

{
  "schema_version": "1.0.0",
  "id": "app_00000000000000000000000001",
  "slug": "mini_crm",
  "name": "Mini CRM",
  "version": 1,
  "settings": { "theme": "light" },
  "objects": [
    {
      "id": "obj_00000000000000000000000001",
      "slug": "clientes",
      "name": "Clientes",
      "fields": [
        { "id": "fld_00000000000000000000000001", "slug": "nombre", "name": "Nombre", "type": "string" },
        { "id": "fld_00000000000000000000000002", "slug": "email", "name": "Email", "type": "string" },
        { "id": "fld_00000000000000000000000003", "slug": "estado", "name": "Estado", "type": "single_select",
          "options": [
            { "id": "opt_00000000000000000000000001", "value": "activo", "label": "Activo", "color": "#16a34a" },
            { "id": "opt_00000000000000000000000002", "value": "inactivo", "label": "Inactivo", "color": "#dc2626" }
          ] }
      ]
    }
  ],
  "pages": [
    {
      "id": "pag_00000000000000000000000001",
      "slug": "clientes",
      "name": "Clientes",
      "path": "/clientes",
      "blocks": [
        { "id": "blk_00000000000000000000000001", "type": "heading", "content": "Clientes" },
        { "id": "blk_00000000000000000000000002", "type": "table",
          "data_source": { "object_id": "obj_00000000000000000000000001" },
          "columns": [
            { "id": "col_00000000000000000000000001", "field_id": "fld_00000000000000000000000001" },
            { "id": "col_00000000000000000000000002", "field_id": "fld_00000000000000000000000002" },
            { "id": "col_00000000000000000000000003", "field_id": "fld_00000000000000000000000003" }
          ] }
      ]
    }
  ],
  "permissions": {
    "roles": [
      { "id": "rol_00000000000000000000000001", "slug": "admin", "name": "Admin", "is_default": true }
    ]
  },
  "workflows": []
}

To add create-from-page: a `button` (on_click=[open_modal]) + a `modal` containing a `form` (mode=create, on_submit=[create_record, close_modal, show_toast, refresh]). The modal block must exist in the SAME page BEFORE the button references it. See the `forms` topic.
TXT,
    ];

    public function name(): string
    {
        return 'framework_reference';
    }

    public function description(): string
    {
        $topics = implode(', ', array_keys(self::TOPICS));

        return "Fetch detailed authoring guidance for ONE area of the App manifest, on demand, so you only carry the rules relevant to the current task. Pass `topic` (one of: {$topics}). Call this BEFORE building in an area you're unsure about: `forms` (data entry/buttons/modals/actions), `workflows` (automation/script.run), `derived_fields` (formula/lookup/rollup + system fields), `expressions` (formula syntax + function catalog), `design` (theme/websites/dashboards/charts), `palette` (brand-derived colour palette + CSS vars), `icons` (named icons + emoji for any block icon), `custom_css` (the scoped raw-CSS escape hatch + targeting hooks), `permissions` (roles, object/page policies, row/field restrictions, access_mode — the ENFORCED access layer), `verification` (simulate_query/inspect/seed), `visual_review` (screenshot review), `connected_objects` (integrations), `example` (a complete minimal manifest). Omit `topic` to list the available topics.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()
                ->description('Which reference section to fetch: '.implode(', ', array_keys(self::TOPICS)).'. Omit to list topics.'),
        ];
    }

    public function handle(Request $request): string
    {
        $topic = strtolower(trim((string) ($request->all()['topic'] ?? '')));

        if ($topic === '') {
            return json_encode([
                'topics' => array_keys(self::TOPICS),
                'note' => 'Pass one of these as `topic` to get its guidance.',
            ], JSON_THROW_ON_ERROR);
        }

        if (! isset(self::TOPICS[$topic])) {
            return json_encode([
                'error' => "Unknown topic '{$topic}'.",
                'topics' => array_keys(self::TOPICS),
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'topic' => $topic,
            'reference' => self::TOPICS[$topic],
        ], JSON_THROW_ON_ERROR);
    }
}
