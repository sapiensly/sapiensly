<script setup lang="ts">
import BigChart from '@/components/admin/BigChart.vue';
import StatCard from '@/components/admin/StatCard.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Coins, Cpu, Layers, Sparkles } from '@lucide/vue';
import { computed } from 'vue';

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
    days: number;
    organization: { id: string; name: string };
    report: Report;
    cap: number | null;
}>();

const ranges = [7, 30, 90];

function money(n: number): string {
    if (n === 0) return '$0';
    return '$' + n.toFixed(n < 1 ? 4 : 2);
}
function num(n: number): string {
    return new Intl.NumberFormat().format(n);
}

const chartSeries = computed(() => [
    { label: 'System', tint: 'var(--sp-accent-blue)', points: props.report.series.system },
    { label: 'Own (BYOK)', tint: 'var(--sp-spectrum-magenta)', points: props.report.series.own },
]);
</script>

<template>
    <Head :title="`AI Usage · ${organization.name}`" />

    <AdminLayout :title="`AI Usage · ${organization.name}`">
        <div class="space-y-6">
            <header class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                    <Link :href="`/admin/ai/usage?days=${days}`" class="inline-flex items-center gap-1 text-xs text-accent-blue hover:underline">
                        <ArrowLeft class="size-3" /> All organizations
                    </Link>
                    <h1 class="text-[22px] font-semibold leading-tight text-ink">{{ organization.name }}</h1>
                    <p class="text-xs text-ink-muted">
                        <span class="font-mono">{{ organization.id }}</span> · AI usage · last {{ days }} days
                        <span v-if="cap != null"> · platform cap {{ money(cap) }}</span>
                    </p>
                </div>
                <div class="inline-flex items-center rounded-pill border border-medium bg-surface p-0.5">
                    <Link
                        v-for="r in ranges"
                        :key="r"
                        :href="`/admin/ai/usage/${organization.id}?days=${r}`"
                        preserve-scroll
                        :class="[
                            'rounded-pill px-3 py-1 text-xs transition-colors',
                            r === days ? 'bg-accent-blue/15 text-accent-blue' : 'text-ink-muted hover:text-ink',
                        ]"
                    >
                        {{ r }}d
                    </Link>
                </div>
            </header>

            <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    :value="money(report.totals.cost)"
                    label="Total spend"
                    :caption="`${num(report.totals.calls)} calls`"
                    :series="report.series.system.map((v, i) => v + report.series.own[i])"
                    :icon="Coins"
                    tint="var(--sp-accent-cyan)"
                />
                <StatCard
                    :value="money(report.by_source.system)"
                    label="System models"
                    caption="Paid by the platform"
                    :series="report.series.system"
                    :icon="Sparkles"
                    tint="var(--sp-accent-blue)"
                />
                <StatCard
                    :value="money(report.by_source.own)"
                    label="Own models (BYOK)"
                    caption="Paid by the tenant"
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

            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-3 text-sm font-medium text-ink">Daily spend (system vs own)</h2>
                <BigChart :series="chartSeries" :height="220" />
            </section>

            <!-- Spend by service (each with its own per-model breakdown) -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-3 text-sm font-medium text-ink">Spend by service</h2>
                <p v-if="report.by_service.length === 0" class="text-xs text-ink-muted">No usage yet.</p>
                <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
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
                            <li v-for="m in s.models" :key="m.model" class="flex items-center justify-between py-1.5 text-xs">
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

            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2 class="mb-3 text-sm font-medium text-ink">Top models by cost</h2>
                <p v-if="report.by_model.length === 0" class="text-xs text-ink-muted">No usage yet.</p>
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
    </AdminLayout>
</template>
