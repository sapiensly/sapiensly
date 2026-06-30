<script setup lang="ts">
import BigChart from '@/components/admin/BigChart.vue';
import StatCard from '@/components/admin/StatCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Coins, Cpu, Layers, Sparkles } from '@lucide/vue';
import { computed } from 'vue';

interface Budget {
    system_monthly_budget: number | null;
    own_monthly_budget: number | null;
    platform_system_cap: number | null;
    alert_threshold_pct: number;
    enforcement_enabled: boolean;
}

interface ModelRow {
    model: string;
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
}

interface Report {
    range_days: number;
    totals: { cost: number; calls: number; input_tokens: number; output_tokens: number };
    by_source: { own: number; system: number };
    by_model: ModelRow[];
    by_service: ServiceRow[];
    series: { labels: string[]; own: number[]; system: number[] };
}

const props = defineProps<{
    scope: { type: string; name: string };
    days: number;
    report: Report;
    budget: Budget | null;
}>();

const ranges = [7, 30, 90];

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
const systemUsagePct = computed(() =>
    systemLimit.value ? Math.min(100, Math.round((props.report.by_source.system / systemLimit.value) * 100)) : null,
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
</script>

<template>
    <Head title="AI Spend" />

    <AppLayoutV2 title="AI Spend">
        <div class="flex flex-col gap-6 p-6">
            <PageHeader title="AI Spend" :description="`${scopeLabel} · last ${days} days`">
                <template #actions>
                    <div class="inline-flex items-center rounded-pill border border-medium bg-surface p-0.5">
                        <Link
                            v-for="r in ranges"
                            :key="r"
                            :href="`/system/ai-spend?days=${r}`"
                            preserve-scroll
                            :class="[
                                'rounded-pill px-3 py-1 text-xs transition-colors',
                                r === days ? 'bg-accent-blue/15 text-accent-blue' : 'text-ink-muted hover:text-ink',
                            ]"
                        >
                            {{ r }}d
                        </Link>
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

            <!-- Daily spend chart -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <header class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-medium text-ink">Daily spend</h2>
                    <div class="flex items-center gap-4 text-xs text-ink-muted">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full" style="background: var(--sp-accent-blue)" /> System
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full" style="background: var(--sp-spectrum-magenta)" /> Own
                        </span>
                    </div>
                </header>
                <BigChart :series="chartSeries" :height="220" />
            </section>

            <!-- Budget (organization scope only) -->
            <section v-if="scope.type === 'organization'" class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-1 text-sm font-medium text-ink">Monthly budget</h2>
                <p class="mb-4 text-xs text-ink-muted">
                    Cap your AI spend. System models (paid by the platform) are also bound by any platform ceiling; own
                    (BYOK) models are capped only if you set a limit.
                </p>

                <div v-if="systemLimit !== null" class="mb-4">
                    <div class="mb-1 flex justify-between text-xs text-ink-muted">
                        <span>System spend this period</span>
                        <span>{{ money(report.by_source.system) }} / {{ money(systemLimit) }} ({{ systemUsagePct }}%)</span>
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

            <!-- Spend by service (each with its own per-model breakdown) -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-3 text-sm font-medium text-ink">Spend by service</h2>
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
                        <ul class="mt-2 divide-y divide-soft/40">
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
                    </div>
                </div>
            </section>

            <!-- Top models -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-3 text-sm font-medium text-ink">Top models by cost</h2>
                <p v-if="report.by_model.length === 0" class="text-xs text-ink-muted">
                    No AI usage recorded in this period yet.
                </p>
                <table v-else class="w-full text-sm">
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
            </section>
        </div>
    </AppLayoutV2>
</template>
