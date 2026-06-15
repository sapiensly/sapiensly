<script setup lang="ts">
import AiTabs from '@/components/admin/AiTabs.vue';
import BigChart from '@/components/admin/BigChart.vue';
import StatCard from '@/components/admin/StatCard.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Coins, Cpu, Layers, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface OrgRow {
    organization_id: string | null;
    cost: number;
    system_cost: number;
    calls: number;
}
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
    by_org: OrgRow[];
    series: { labels: string[]; own: number[]; system: number[] };
}

const props = defineProps<{ days: number; report: Report }>();

const { t } = useI18n();
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
    <Head :title="t('admin.nav.ai')" />

    <AdminLayout :title="t('admin.nav.ai')">
        <div class="space-y-6">
            <header class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-[22px] font-semibold leading-tight text-ink">{{ t('admin.ai.heading') }}</h1>
                    <p class="text-xs text-ink-muted">Platform-wide AI usage and cost · last {{ days }} days</p>
                </div>
                <div class="inline-flex items-center rounded-pill border border-medium bg-surface p-0.5">
                    <Link
                        v-for="r in ranges"
                        :key="r"
                        :href="`/admin/ai/usage?days=${r}`"
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

            <AiTabs current="usage" />

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
                    caption="Paid by tenants"
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

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <section class="rounded-sp-sm border border-soft bg-navy p-5">
                    <h2 class="mb-3 text-sm font-medium text-ink">Top organizations by spend</h2>
                    <p v-if="report.by_org.length === 0" class="text-xs text-ink-muted">No usage yet.</p>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-soft text-left text-xs uppercase tracking-wide text-ink-subtle">
                                <th class="py-2 font-medium">Organization</th>
                                <th class="py-2 text-right font-medium">System</th>
                                <th class="py-2 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in report.by_org" :key="o.organization_id ?? 'personal'" class="border-b border-soft/50">
                                <td class="py-2 font-mono text-xs text-ink">{{ o.organization_id ?? '— (personal)' }}</td>
                                <td class="py-2 text-right text-ink-muted">{{ money(o.system_cost) }}</td>
                                <td class="py-2 text-right font-medium text-ink">{{ money(o.cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="rounded-sp-sm border border-soft bg-navy p-5">
                    <h2 class="mb-3 text-sm font-medium text-ink">Top models by cost</h2>
                    <p v-if="report.by_model.length === 0" class="text-xs text-ink-muted">No usage yet.</p>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-soft text-left text-xs uppercase tracking-wide text-ink-subtle">
                                <th class="py-2 font-medium">Model</th>
                                <th class="py-2 text-right font-medium">Calls</th>
                                <th class="py-2 text-right font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in report.by_model" :key="m.model" class="border-b border-soft/50">
                                <td class="py-2 text-ink">{{ m.model }}</td>
                                <td class="py-2 text-right text-ink-muted">{{ num(m.calls) }}</td>
                                <td class="py-2 text-right font-medium text-ink">{{ money(m.cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
