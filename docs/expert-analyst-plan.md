# The Expert Analyst — plan

**Goal.** An expert data analyst that recommends analyses which make *business* sense, exploits the *full* charting/BI arsenal we already built, and can be reused from **any** Sapiensly surface — the App Builder panel, the builder chat, MCP, agents/chatbots, and Living Decks.

Today it is none of those three: it is a five-recommendation panel, bolted to one controller, that emits four of the twelve chart types we support and cannot see half the data in a given app.

This document reconstructs the plan (the original lived only in a session transcript) and re-bases it on what the code actually is as of `75ff412`.

---

## Status (updated 2026-07-13)

| Phase | | |
|---|---|---|
| 1 · expert facts | **done** | data quality · cross-source join · derived metrics · run-rate |
| 2 · reader port | **done** | `ObjectRowSource` + `FieldPaths`; the analyst can finally see native objects |
| 3 · core extraction | **done** | `AnalystCore` emits Findings; no page, no block, no cache |
| 6 · surfaces | **done** | `analyze_app_data` over MCP → every agent and chatbot, via one registration |
| 8 · rendered truth | **done** | every chart aggregates where the data lives; `limit` caps categories, not evidence |
| 4 · new finders | **done** | correlation → scatter · volume-vs-rate → combo · anomaly · rate KPI · flow → sankey · composition → stacked · distribution → box · seasonality → quarterly. **Cohort deliberately not built — see below** |
| 5 · business sense | **done** | benchmarks that exist (declared target → own best → say nothing); typing reads names + values; ranking survives an unknown domain; `gaps()` honours what's shown |
| 7 · consolidation | **done** | one analyst (builder chat + panel), one fact guard, one measure typing, one sector taxonomy. `DashboardSpecSuggester` deliberately kept — see below |

### What Phase 7 found (the audit was partly wrong)

**There were never "three narrators".** `FactNarrator` is a deterministic string
formatter that *produces* the numbers; it is not an LLM rewrite at all. There are
two LLM rewrite paths plus Express's voice gate — and `keepsFacts()` was **the only
number-preservation check in the entire `app/` tree**. The other two accepted
whatever the model wrote: Express validated only that the model returned the right
*count* of cards, and the deck narrator's "NEVER touch values" was enforced by
nothing. That is what `FactGuard` fixes; the duplication was the smaller problem.

**"Four chart-recommendation engines" was two-and-a-half.** `SuggestSpecPhase` is a
phase, not an engine. `profile_object`'s suggestion half *was* a rival and is
retired. That leaves two, and they do different jobs:

- **`AnalystCore`** — reads a board's data and proposes analyses to ADD to it.
- **`DashboardSpecSuggester`** — builds a WHOLE dashboard from a prompt (title,
  KPI row, charts, insights, layout), inside an 8-phase pipeline with its own
  gates, verifier and prompts.

**`DashboardSpecSuggester` is deliberately NOT merged.** They already share the
layers where disagreement would be dangerous — `ComputedFactsBuilder` (the facts)
and `SemanticProfile` (what may legally be summed), now including the measure
typing, so neither can sum a percentage the other refuses to. What remains
duplicated is chart-FORM selection (donut ≤8, hbar 9+), which produces
differently-shaped dashboards, not wrong ones. Rewiring a production pipeline's
spec generation to buy consistency of taste is not a trade worth making; the
consistency that matters is already bought.

**Cohort / retention (was 4.9) is not built, on purpose.** The 2-D pivot exists in
the query layer and is reachable from MCP, but **no block renders a matrix**.
Faking it as a stacked bar would answer a different question than the one asked.
Building a `pivot` block is the prerequisite — that decision is still open.

The analyst now emits: pareto, area (trend), donut/hbar (breakdown), gauge, scatter
(correlation), dual-axis combo, sankey (flow), stacked bar (composition), box
(distribution), quarterly bar (seasonality), stat with `ratio_denominator` (rate
KPI), and insight (cross-source join, derived metric, anomaly).

Phase 8 is complete: breakdowns resolve to groups, a second categorical to a pivot
({group, group2, value} — the one payload a stacked bar, a radar and a sankey all
read), and a combo to one grouped series per overlaid measure. Only `scatter` and
`box` still take rows, because they plot records rather than categories.

## Where we are

**Phase 1 (expert) is done** — four commits, all covered by tests:

| | |
|---|---|
| `3690365` · 1.4 | `DataQualityCheck` — freshness + completeness chips ("trust the data before concluding") |
| `fc98c76` · 1.1 | `CrossSourceAnalyzer` — joins two sources on a shared dimension, emits an **insight** (volume vs. performance) |
| `4e6bfe3` · 1.2 | `DerivedMetricProposer` — constructs ratios the board doesn't carry (reopen rate, backlog %) |
| `75ff412` · 1.3 | run-rate ETA on declining trends |

What the engine produces today (`ChartRecommender::candidatesFor`, `app/Services/Builder/ChartRecommender.php:201`): **six kinds** — `pareto`, `trend` (area), `gauge`, `breakdown` (donut/hbar), `cross` (insight), `derived` (insight). Ranked by a hardcoded base score + a per-sector "headline" bonus, top 5.

---

## The four things blocking "expert"

These are findings, not opinions — each is a concrete limit in the current code.

### B1 · The analyst is half-blind (the reader fracture)

`ChartRecommender.php:61` filters objects to `source.type === 'connected'` and reads exclusively through `ConnectedObjectReader`. **Native, record-backed objects — the ordinary case — are invisible to it.** They live behind `RecordQueryService`, which no analysis path shares.

The abstraction already exists elsewhere and is the pattern to copy: `app/Ai/Tools/Runtime/Agent/AggregateObjectTool` is *already* source-agnostic (SQL for internal objects, `InMemoryAggregator` for connected ones).

Everything else in this plan is downstream of fixing this.

### B2 · The analysis and the render disagree

The analyst reasons over `SAMPLE = 500` rows (`ChartRecommender.php:31`) pulled with **no order, no date window, no filter pushed down** — an arbitrary head of the dataset. Then the chart block it inserts *also* doesn't aggregate in SQL: `BlockDataResolver.php:158` routes `chart` to raw `queryRows()` (default `limit = 50`, `RecordQueryService.php:112`) and `BlockChart.vue:175-230` re-implements count/sum/avg/min/max **in JavaScript**.

So a recommendation can be arithmetically true on the sample and still render a chart that plots a different (smaller) reality. Only `stat` / `metric_grid` / `gauge` / `progress` / `insight.compute` / `funnel` reach the real SQL aggregate path — which is also the only path with `distinct_count`, `median`, `p90`, `p95`.

### B3 · It uses ~⅓ of the arsenal

Schema-legal, renderer-complete, **never auto-generated by anything**:

| Unused capability | The analyst move it unlocks |
|---|---|
| `scatter` + a correlation coefficient | *"These two move together (r=0.81)."* The most classic analyst read — **no correlation detection exists anywhere.** |
| `sankey` | Flow between two categoricals: reason → owner, channel → outcome, stage → stage. |
| `series[]` combo + `axis: "right"` | **Volume bars + rate line on a secondary axis** — the executive chart. `grep "'series' =>"` across `app/` = **0 hits.** |
| `stacked` + `series_field_id` | Composition over time (month × status). |
| `box` | Distribution, spread, outliers per category (the suggester emits it; the recommender never does). |
| `radar` | Multi-metric entity comparison (seller → SLA / volume / resolution). |
| `ratio_denominator` on `stat` | Win rate, conversion, attach rate as a **live KPI** — 0 hits in every generator. `DerivedMetricProposer` computes exactly these ratios and then ships them as *prose* instead of a KPI block. |
| `secondGroupFieldId` (2-D pivot, `RecordQueryService.php:701`) | Cohort / retention matrices. Reachable from MCP; **no block consumes it.** |
| `p90` / `p95` | Tail-latency / SLA KPIs. |
| `bucket: quarter\|year` | QoQ / YoY seasonality. Generators only ever pick `week`/`month`. |
| `drill_param` | Click a category → re-scope the whole board. |
| `{op: "related"}` filter (`RecordQueryService.php:1060`) | Filter object A by a condition on object B **in SQL** — instead of `CrossSourceAnalyzer` joining in PHP over samples. |

And two facts we **already compute and throw away**:
- `ComputedFactsBuilder::anomaly()` (`:484`) — a 2σ outlier with date, value, z-score, direction. **`candidatesFor()` never reads it.** There is no anomaly recommendation despite the fact being free.
- `ComputedFactsBuilder::crossFacts()` (`:155`) — peak-week alignment and a **Pearson co-movement lead (|r| ≥ 0.7)**. Never called by the recommender; only feeds Express.

### B4 · "Business sense" is thinner than it looks

- **The relevance bonus silently vanishes on unknown domains.** `DomainClassifier` needs ≥2 keyword signals to name a sector; otherwise `general` — whose headline-term list is **empty**. On any unrecognised business, the +12 headline bonus is always 0 and ranking collapses to pure kind-precedence (cross > pareto > trend > gauge > derived > breakdown).
- **The gauge target is invented**: `$target = $value <= 100 ? 80 : round($value * 1.15)` (`ChartRecommender.php:304`). We tell the user they're 3.2 points from a goal we made up. There is no target in the manifest, no config, no learned baseline.
- **Measure typing is slug-regex only.** `SemanticProfile::measureTypeOf()` reads `slug`, never `name`, and its value-based fallback is **dead in this pipeline** (every caller passes fields without values). Consequence: **any numeric column whose slug doesn't match a regex is silently ADDITIVE** — it gets summed. We can sum a percentage.
- **`gaps()` ignores its `$existing` argument** (`:431`) — it advertises "no gauge for X" even when the board already has that gauge.

---

## The shape of the fix

```
                    ┌──────────────── Analyst Core (surface-agnostic) ──────────────┐
  RowSource port ──►│  facts → finders → findings → rank → narrate → Finding[]      │
  (native+connected)│                                                                │
                    └───────┬──────────────┬──────────────┬─────────────┬───────────┘
                            │              │              │             │
                    manifest adapter   deck adapter   chat adapter   MCP tool
                    (chart/KPI block)  (DeckEditor)   (message)      (→ agents free)
```

A **`Finding`** is the unit of currency: `{identity, semantic_key, kind, kicker, title, why, flag, fact, preview, spec}` — with **no page, no cache, no block ids**. Dedup becomes `exclude(string[] $semanticKeys)` instead of `$page['blocks']`.

The core emits Findings. Each surface owns the last 50 lines that turn a Finding into *its* artifact — and every one of those renderers already exists (`AppManifestService`/`ProposeChangeTool` for manifests, `DeckEditor` for decks, `PlatformToolsFactory` for agents).

---

## Phases

### Phase 2 — The reader port *(unblocks everything)*

Extract a `RowSource` / `SampledRows` port: `for(object, actor, window?) → rows`, with two drivers — `RecordQueryService` (native) and `ConnectedObjectReader` (connected). Copy the shape of `AggregateObjectTool`, which already does exactly this split.

Then push down what we currently ignore: **order by the date field, window to a period, cap by relevance** — instead of taking an arbitrary 500-row head. Sampling stays (it's cheap and it's what previews need), but it becomes a *defensible* sample.

*Acceptance:* the recommender produces findings for a native-object app. Today it produces none.

### Phase 3 — The core extraction *(makes it reusable)*

1. `Finding` DTO + `AnalystCore::analyze(Dataset $d, array $exclude = []) : Finding[]` — no `App`, no `$page`, no `TenantCache` write.
2. The three satellites (`CrossSourceAnalyzer`, `DerivedMetricProposer`, `DataQualityCheck`) move in **as-is** — they are already pure (they take `$byObject` and return arrays).
3. The spec cache (`present()` → `TenantCache`, 1800s, 410-on-expiry) becomes an *App-Builder adapter* concern, not core.
4. `ChartRecommender` becomes the manifest adapter: core → blocks.

*Acceptance:* the core can be called with no App and no manifest page, and returns the same findings.

### Phase 4 — New finders: exploit the arsenal

Each new finder = a fact + a chart form + a business sentence. Ordered by analyst value:

| # | Finder | Fact | Emits |
|---|---|---|---|
| 4.1 | **Correlation** | Pearson over shared buckets — **`crossFacts()` already computes it** | `scatter` + r in the narrative |
| 4.2 | **Volume vs. rate** | additive measure + ratio measure on one dimension | **combo `series[]`, dual axis** — the executive chart |
| 4.3 | **Anomaly** | `facts['anomalia']` — **already computed, currently discarded** | `line`/`area` with the outlier called out |
| 4.4 | **Rate KPI** | the ratios `DerivedMetricProposer` already finds | `stat` + **`ratio_denominator`** (a live KPI, not prose) |
| 4.5 | **Flow** | two categoricals that co-occur | `sankey` |
| 4.6 | **Composition over time** | dimension × date | `stacked` bar + `series_field_id` |
| 4.7 | **Distribution** | spread/skew/outliers per category | `box` |
| 4.8 | **Seasonality** | `bucket: quarter\|year` | YoY/QoQ comparison |
| 4.9 | **Cohort** | `secondGroupFieldId` 2-D pivot | needs a `pivot`/matrix block — the one genuinely missing renderer |

Raise `MAX_RECS` above 5 and make the mix diverse (the ranking currently lets one kind dominate).

### Phase 5 — Business sense

- **Real targets, not `80`.** A `target` on the manifest field (or a learned baseline: last period, or the top-quartile of the dimension). If we have no target, say so — don't invent one.
- **Fix measure typing**: pass values so the fallback actually runs; read `name` as well as `slug`. Never sum a ratio.
- **Fix `gaps($existing)`** — honour the argument.
- **Ranking that survives an unknown domain**: when the sector is `general`, relevance must come from the data (effect size, concentration, volume share), not from an empty term list.
- **Lead with the "so what"** — every finding states the action, not the shape ("that's where improvement pays most", not "a Pareto shows this").

### Phase 6 — Surfaces

1. **MCP tool** (`App\Ai\Tools\**` + a shim in `SapiensServer::TOOLS`). Because `PlatformToolsFactory` bridges MCP tools into every internal agent run, **this single registration lights up MCP + every agent + every chatbot at once**, with RLS and authorization already enforced.
2. **Builder chat** — `BuilderAiService` currently recommends charts through a *different* engine than the builder's own «Agregar gráfica» panel. Point both at the core. (Note: its tool array is copy-pasted at `:180-218` and `:390-428` — extract it first.)
3. **Decks** — a Finding maps almost 1:1 onto a `chart` / `metrics` / `big_number` slide with a takeaway. Adapter goes through `DeckEditor::apply` (the single write path). This is the only surface needing genuinely new code.

### Phase 7 — Consolidation *(pay the debt this exposes)*

We have **four independent engines** answering "what chart should this data get?": `ChartRecommender::candidatesFor`, `Ai\Tools\Builder\ProfileObjectTool`, `Manifest\DashboardSpecSuggester`, and `Express\Phases\SuggestSpecPhase`. Two of them even carry the same rules in their docblocks ("donut 2-8, hbar 9+, never a pie on high cardinality") in separate implementations.

Also: **three narrators** with one job (`RecommendationNarrator`, `Express\FactNarrator`, `Slides\DeckNarrator` — all "LLM rewrites copy without breaking the numbers") → one `FactPreservingNarrator` with three prompts. And **sector knowledge twice** (`ListDashboardBlueprintsTool::BLUEPRINTS` hardcoded vs. `DomainClassifier` inferred).

Retire the suggestion-halves onto the core. Keep `PlanDashboardTool` — it lints *layout*, which is orthogonal and worth keeping.

### Phase 8 — Correctness of the rendered chart *(B2)*

Route chart blocks through `groupedAggregate()` instead of raw rows + JS folding. Until this lands, every recommendation we make is only as true as the first 50 rows the block happens to fetch.

---

## Open questions

1. **Is the 500-row sample defensible at all** for Pareto/trend once we can push order+window down, or should the core aggregate in SQL and sample only for previews?
2. **A `pivot`/matrix block** — the 2-D pivot exists in the query layer and in MCP, but no block renders it. Build it, or keep cohort analysis as an insight?
3. **Turn narration on?** `RecommendationNarrator` is shipped but off (`config/express.php:72`). The deterministic Spanish is decent; the AI adds voice and reranks. Decide.
4. **Locale** — `$es = $lang !== 'en'` is binary, and `ManualChat.vue` hardcodes Spanish chrome while the backend branches. Pick one.
