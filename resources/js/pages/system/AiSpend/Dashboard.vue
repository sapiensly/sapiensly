<script setup lang="ts">
import BigChart from '@/components/admin/BigChart.vue';
import StatCard from '@/components/admin/StatCard.vue';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Coins, Cpu, Layers, Sparkles } from '@lucide/vue';
import { computed } from 'vue';

interface ModelRow {
    model: string;
    cost: number;
    calls: number;
    input_tokens: number;
    output_tokens: number;
}

interface Report {
    range_days: number;
    totals: { cost: number; calls: number; input_tokens: number; output_tokens: number };
    by_source: { own: number; system: number };
    by_model: ModelRow[];
    series: { labels: string[]; own: number[]; system: number[] };
}

const props = defineProps<{
    scope: { type: string; name: string };
    days: number;
    report: Report;
}>();

const ranges = [7, 30, 90];

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
