<script setup lang="ts">
import BigChart from '@/components/admin/BigChart.vue';
import StatCard from '@/components/admin/StatCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Coins, Cpu, Layers, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';

interface Budget {
    system_monthly_budget: number | null;
    own_monthly_budget: number | null;
    platform_system_cap: number | null;
    alert_threshold_pct: number;
    enforcement_enabled: boolean;
    /** Budget-month-to-date spend — independent of the period picker. */
    period_to_date: { since: string; own: number; system: number };
}

interface ModelRow {
    model: string;
    cost: number;
    calls: number;
    input_tokens: number;
    output_tokens: number;
}

/** What the spend was made on: an app, a chatbot, a deck, a knowledge base… */
interface ArtifactRow {
    name: string | null;
    kind: string | null;
    type: string | null;
    id: string | null;
    cost: number;
    calls: number;
    input_tokens: number;
    output_tokens: number;
}

interface ServiceRow {
    service: string;
    cost: number;
    calls: number;
    input_tokens: number;
    output_tokens: number;
    models: ModelRow[];
    artifacts?: ArtifactRow[];
}

interface Period {
    key: string;
    label: string;
    granularity: 'hour' | 'day';
    since: string;
}

interface Report {
    range_days: number;
    period: Period;
    totals: { cost: number; calls: number; input_tokens: number; output_tokens: number };
    by_source: { own: number; system: number };
    by_model: ModelRow[];
    by_service: ServiceRow[];
    series: { labels: string[]; own: number[]; system: number[] };
}

const props = defineProps<{
    scope: { type: string; name: string };
    period: Period;
    periods: { key: string; label: string; short: string }[];
    report: Report;
    budget: Budget | null;
}>();

const budgetForm = useForm({
    system_monthly_budget: props.budget?.system_monthly_budget ?? null,
    own_monthly_budget: props.budget?.own_monthly_budget ?? null,
    alert_threshold_pct: props.budget?.alert_threshold_pct ?? 80,
    enforcement_enabled: props.budget?.enforcement_enabled ?? true,
});

// Effective system cap = the lower of the org's budget and any platform ceiling.
const systemLimit = computed<number | null>(() => {
    const candidates = [props.budget?.system_monthly_budget, props.budget?.platform_system_cap].filter(
        (v): v is number => v !== null && v !== undefined,
    );
    return candidates.length ? Math.min(...candidates) : null;
});
// A monthly budget is measured against the budget month, never against the
// picked window — otherwise "Today" would read as 2% of the cap and look safe.
const systemSpentThisBudgetPeriod = computed(() => props.budget?.period_to_date.system ?? 0);
const systemUsagePct = computed(() =>
    systemLimit.value ? Math.min(100, Math.round((systemSpentThisBudgetPeriod.value / systemLimit.value) * 100)) : null,
);

function saveBudget(): void {
    budgetForm.post('/system/ai-spend/budget', { preserveScroll: true });
}

function money(n: number): string {
    if (n === 0) return '$0';
    return '$' + n.toFixed(n < 1 ? 4 : 2);
}
function num(n: number): string {
    return new Intl.NumberFormat().format(n);
}

const combinedSeries = computed(() =>
    props.report.series.own.map((v, i) => Math.round((v + props.report.series.system[i]) * 1_000_000) / 1_000_000),
);

const chartSeries = computed(() => [
    { label: 'System', tint: 'var(--sp-accent-blue)', points: props.report.series.system },
    { label: 'Own (BYOK)', tint: 'var(--sp-spectrum-magenta)', points: props.report.series.own },
]);

const scopeLabel = computed(() =>
    props.scope.type === 'organization' ? `Organization · ${props.scope.name}` : `Personal · ${props.scope.name}`,
);

function periodHref(key: string): string {
    return `/system/ai-spend?period=${key}`;
}

/** A single-day window is charted hourly, so the heading has to follow. */
const seriesHeading = computed(() => (props.period.granularity === 'hour' ? 'Hourly spend' : 'Daily spend'));

/**
 * Bucket labels come back as 'YYYY-MM-DD HH:00' or 'YYYY-MM-DD'. The axis only
 * has room for the part that varies within the window.
 */
const chartLabels = computed(() =>
    props.report.series.labels.map((label) => {
        if (props.period.granularity === 'hour') return label.slice(11);
        return new Date(`${label}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    }),
);

/** The tooltip has room for the whole bucket — "15 Jul, 09:00", not just "09:00". */
const chartTooltipLabels = computed(() =>
    props.report.series.labels.map((label) => {
        const day = new Date(`${label.slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
        });
        return props.period.granularity === 'hour' ? `${day}, ${label.slice(11)}` : day;
    }),
);

/**
 * Axis money, not table money: `money()` pads sub-dollar values to four
 * decimals, which turns a tick into "$0.1500" and crowds the column.
 */
function axisMoney(n: number): string {
    if (n === 0) return '$0';
    if (n >= 10) return '$' + n.toFixed(0);
    // Fixed two decimals down to a cent so the ticks line up as a column:
    // trimming zeros put "$1.20" next to "$0.9".
    if (n >= 0.01) return '$' + n.toFixed(2);
    return '$' + parseFloat(n.toFixed(4)).toString();
}

function shortDate(iso: string): string {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

// "What models did we spend on" and "what did we spend them on" are two
// readings of the same rows, so they share a card and swap the list.
const serviceBreakdown = ref<'models' | 'artifacts'>('models');

/**
 * An artifact whose row is gone keeps its line — the spend is real and the
 * service total has to keep adding up — so it falls back to the bare id, and
 * spend the ledger could not attribute at all says so plainly.
 */
function artifactName(a: ArtifactRow): string {
    return a.name ?? a.id ?? 'Not attributed';
}
function artifactCaption(a: ArtifactRow): string | null {
    if (a.id === null) return 'Recorded before this spend could be named';
    if (a.name === null) return a.kind ? `${a.kind} · no longer exists` : null;
    return a.kind ? `${a.kind} · ${a.id}` : a.id;
}
</script>

<template>
    <Head title="AI Spend" />

    <AppLayoutV2 title="AI Spend">
        <!--
          The layout already pads the content area. This page added `p-6` on
          top of it, so on a phone the cards sat behind 40px of gutter per side
          — the only page in the app that doubled up. Horizontal padding is
          dropped below `sm` and left to the layout; `sm` and up is unchanged.
        -->
        <div class="flex flex-col gap-6 px-0 py-4 sm:p-6">
            <PageHeader title="AI Spend" :description="`${scopeLabel} · ${period.label.toLowerCase()}`">
                <template #actions>
                    <!--
                      Six windows do not fit a phone in one row, and wrapping a
                      pill group splits its border in half. Scroll it instead —
                      the same treatment the models table below already gets.
                    -->
                    <div class="-mx-1 max-w-full overflow-x-auto px-1">
                        <div class="inline-flex w-max items-center rounded-pill border border-medium bg-surface p-0.5">
                            <Link
                                v-for="p in periods"
                                :key="p.key"
                                :href="periodHref(p.key)"
                                preserve-scroll
                                :title="p.label"
                                :class="[
                                    'whitespace-nowrap rounded-pill px-3 py-1 text-xs transition-colors',
                                    p.key === period.key ? 'bg-accent-blue/15 text-accent-blue' : 'text-ink-muted hover:text-ink',
                                ]"
                            >
                                {{ p.short }}
                            </Link>
                        </div>
                    </div>
                </template>
            </PageHeader>

            <!-- KPI row -->
            <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    :value="money(report.totals.cost)"
                    label="Total spend"
                    :caption="`${num(report.totals.calls)} calls`"
                    :series="combinedSeries"
                    :icon="Coins"
                    tint="var(--sp-accent-cyan)"
                />
                <StatCard
                    :value="money(report.by_source.system)"
                    label="System models"
                    caption="Billed by the platform"
                    :series="report.series.system"
                    :icon="Sparkles"
                    tint="var(--sp-accent-blue)"
                />
                <StatCard
                    :value="money(report.by_source.own)"
                    label="Own models (BYOK)"
                    caption="Billed to your provider"
                    :series="report.series.own"
                    :icon="Layers"
                    tint="var(--sp-spectrum-magenta)"
                />
                <StatCard
                    :value="num(report.totals.input_tokens + report.totals.output_tokens)"
                    label="Tokens"
                    :caption="`${num(report.totals.input_tokens)} in · ${num(report.totals.output_tokens)} out`"
                    :icon="Cpu"
                    tint="var(--sp-success)"
                />
            </section>

            <!-- Spend chart: daily, or hourly for a single-day window -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <header class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-medium text-ink">{{ seriesHeading }}</h2>
                    <div class="flex items-center gap-4 text-xs text-ink-muted">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full" style="background: var(--sp-accent-blue)" /> System
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full" style="background: var(--sp-spectrum-magenta)" /> Own
                        </span>
                    </div>
                </header>
                <BigChart
                    :series="chartSeries"
                    :height="220"
                    :labels="chartLabels"
                    :tooltip-labels="chartTooltipLabels"
                    :format-value="axisMoney"
                />
            </section>

            <!-- Budget (organization scope only) -->
            <section v-if="scope.type === 'organization'" class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-1 text-sm font-medium text-ink">Monthly budget</h2>
                <p class="mb-4 text-xs text-ink-muted">
                    Cap your AI spend. System models (paid by the platform) are also bound by any platform ceiling; own
                    (BYOK) models are capped only if you set a limit.
                </p>

                <div v-if="systemLimit !== null && budget" class="mb-4">
                    <div class="mb-1 flex justify-between gap-3 text-xs text-ink-muted">
                        <span>System spend since {{ shortDate(budget.period_to_date.since) }}</span>
                        <span class="whitespace-nowrap">
                            {{ money(systemSpentThisBudgetPeriod) }} / {{ money(systemLimit) }} ({{ systemUsagePct }}%)
                        </span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-surface">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="(systemUsagePct ?? 0) >= 100 ? 'bg-sp-danger' : (systemUsagePct ?? 0) >= budgetForm.alert_threshold_pct ? 'bg-sp-warning' : 'bg-accent-blue'"
                            :style="{ width: `${systemUsagePct}%` }"
                        />
                    </div>
                    <p v-if="budget?.platform_system_cap != null" class="mt-1 text-[10px] text-ink-subtle">
                        Platform ceiling: {{ money(budget.platform_system_cap) }}
                    </p>
                </div>

                <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="saveBudget">
                    <label class="text-xs text-ink-muted">
                        System monthly budget (USD)
                        <input
                            v-model.number="budgetForm.system_monthly_budget"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="No limit"
                            class="mt-1 w-full rounded-md border border-medium bg-surface px-3 py-2 text-sm text-ink"
                        />
                    </label>
                    <label class="text-xs text-ink-muted">
                        Own (BYOK) monthly budget (USD)
                        <input
                            v-model.number="budgetForm.own_monthly_budget"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="No limit"
                            class="mt-1 w-full rounded-md border border-medium bg-surface px-3 py-2 text-sm text-ink"
                        />
                    </label>
                    <label class="text-xs text-ink-muted">
                        Alert at (% of budget)
                        <input
                            v-model.number="budgetForm.alert_threshold_pct"
                            type="number"
                            min="1"
                            max="100"
                            class="mt-1 w-full rounded-md border border-medium bg-surface px-3 py-2 text-sm text-ink"
                        />
                    </label>
                    <label class="flex items-center gap-2 self-end text-xs text-ink">
                        <input v-model="budgetForm.enforcement_enabled" type="checkbox" class="rounded border-medium" />
                        Block calls when over budget
                    </label>
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="budgetForm.processing"
                            class="rounded-pill bg-accent-blue px-4 py-1.5 text-xs font-medium text-white disabled:opacity-50"
                        >
                            {{ budgetForm.processing ? 'Saving…' : 'Save budget' }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- Spend by service, split either by model or by what it was spent on -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <header class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-medium text-ink">Spend by service</h2>
                    <div class="inline-flex items-center rounded-pill border border-medium bg-surface p-0.5">
                        <button
                            v-for="mode in (['models', 'artifacts'] as const)"
                            :key="mode"
                            type="button"
                            class="rounded-pill px-3 py-1 text-xs capitalize transition-colors"
                            :class="
                                serviceBreakdown === mode
                                    ? 'bg-accent-blue/15 text-accent-blue'
                                    : 'text-ink-muted hover:text-ink'
                            "
                            @click="serviceBreakdown = mode"
                        >
                            {{ mode }}
                        </button>
                    </div>
                </header>
                <p v-if="report.by_service.length === 0" class="text-xs text-ink-muted">
                    No AI usage recorded in this period yet.
                </p>
                <div v-else class="flex flex-col gap-3">
                    <div
                        v-for="s in report.by_service"
                        :key="s.service"
                        class="rounded-sp-sm border border-soft/60 bg-surface/40 p-4"
                    >
                        <header class="flex items-baseline justify-between gap-3">
                            <h3 class="text-sm font-medium text-ink">{{ s.service }}</h3>
                            <div class="text-right">
                                <span class="text-sm font-semibold text-ink">{{ money(s.cost) }}</span>
                                <span class="ml-2 text-xs text-ink-subtle">{{ num(s.calls) }} calls</span>
                            </div>
                        </header>
                        <ul v-if="serviceBreakdown === 'models'" class="mt-2 divide-y divide-soft/40">
                            <li
                                v-for="m in s.models"
                                :key="m.model"
                                class="flex items-center justify-between py-1.5 text-xs"
                            >
                                <span class="text-ink-muted">{{ m.model }}</span>
                                <span class="flex items-center gap-3">
                                    <span class="text-ink-subtle">{{ num(m.input_tokens + m.output_tokens) }} tok</span>
                                    <span class="w-16 text-right font-medium text-ink">{{ money(m.cost) }}</span>
                                </span>
                            </li>
                        </ul>
                        <ul v-else class="mt-2 divide-y divide-soft/40">
                            <li
                                v-for="a in s.artifacts ?? []"
                                :key="`${a.type}:${a.id}`"
                                class="flex items-center justify-between gap-3 py-1.5 text-xs"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-ink-muted">{{ artifactName(a) }}</span>
                                    <span
                                        v-if="artifactCaption(a)"
                                        class="block truncate font-mono text-[10px] text-ink-subtle"
                                    >
                                        {{ artifactCaption(a) }}
                                    </span>
                                </span>
                                <span class="flex shrink-0 items-center gap-3">
                                    <span class="text-ink-subtle">{{ num(a.input_tokens + a.output_tokens) }} tok</span>
                                    <span class="w-16 text-right font-medium text-ink">{{ money(a.cost) }}</span>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Top models -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-3 text-sm font-medium text-ink">Top models by cost</h2>
                <p v-if="report.by_model.length === 0" class="text-xs text-ink-muted">
                    No AI usage recorded in this period yet.
                </p>
                <!--
                  Four numeric columns do not fit a phone: at 390px the model
                  name gets crushed to a couple of characters. Scroll the table
                  instead of shrinking it — same treatment as the benchmark
                  table in playground/BenchmarkResults.vue.
                -->
                <div v-else class="-mx-1 overflow-x-auto px-1">
                <table class="w-full min-w-[420px] text-sm">
                    <thead>
                        <tr class="border-b border-soft text-left text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="py-2 font-medium">Model</th>
                            <th class="py-2 text-right font-medium">Calls</th>
                            <th class="py-2 text-right font-medium">Tokens</th>
                            <th class="py-2 text-right font-medium">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in report.by_model" :key="m.model" class="border-b border-soft/50">
                            <td class="py-2 text-ink">{{ m.model }}</td>
                            <td class="py-2 text-right text-ink-muted">{{ num(m.calls) }}</td>
                            <td class="py-2 text-right text-ink-muted">{{ num(m.input_tokens + m.output_tokens) }}</td>
                            <td class="py-2 text-right font-medium text-ink">{{ money(m.cost) }}</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </section>
        </div>
    </AppLayoutV2>
</template>
