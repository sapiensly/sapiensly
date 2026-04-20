<script setup lang="ts">
import AuditRow from '@/components/admin-v2/AuditRow.vue';
import HealthRow from '@/components/admin-v2/HealthRow.vue';
import Sparkline from '@/components/admin-v2/Sparkline.vue';
import StatCard from '@/components/admin-v2/StatCard.vue';
import AdminV2Layout from '@/layouts/AdminV2Layout.vue';
import type { DashboardProps } from '@/lib/admin/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Clock,
    Download,
    RefreshCw,
    Sparkles,
    TrendingUp,
    User,
    Zap,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<DashboardProps>();

const { t } = useI18n();

function refresh() {
    router.reload({
        only: ['stats', 'layers', 'spend', 'health', 'audit'],
        preserveScroll: true,
    });
}

const allSystemsGo = computed(() =>
    props.health.every((h) => h.status === 'ok'),
);

const totalCalls = computed(() =>
    props.spend ? props.spend.providers.reduce((s, p) => s + p.calls, 0) : 0,
);

const spendSegments = computed(() => {
    if (!props.spend || totalCalls.value === 0) return [];
    return props.spend.providers.map((p) => ({
        ...p,
        pct: (p.calls / totalCalls.value) * 100,
    }));
});

const layerCards = computed(() => {
    if (!props.layers) return [];
    return [
        {
            key: 'understand' as const,
            label: t('admin_v2.dashboard.layers.understand'),
            count: props.layers.understand.count,
            subtitle: props.layers.understand.subtitle,
            series: props.layers.understand.series,
            tint: 'var(--sp-spectrum-magenta)',
        },
        {
            key: 'discover' as const,
            label: t('admin_v2.dashboard.layers.discover'),
            count: props.layers.discover.count,
            subtitle: props.layers.discover.subtitle,
            series: props.layers.discover.series,
            tint: 'var(--sp-spectrum-cyan)',
        },
        {
            key: 'resolve' as const,
            label: t('admin_v2.dashboard.layers.resolve'),
            count: props.layers.resolve.count,
            subtitle: props.layers.resolve.subtitle,
            series: props.layers.resolve.series,
            tint: 'var(--sp-spectrum-indigo)',
        },
    ];
});
</script>

<template>
    <Head :title="t('admin_v2.nav.dashboard')" />

    <AdminV2Layout :title="t('admin_v2.nav.dashboard')">
        <div class="space-y-6">
            <!-- Header: title + subtitle + Export / Refresh buttons -->
            <header class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="text-[22px] font-semibold leading-tight text-ink">
                        {{ t('admin_v2.dashboard.heading_full') }}
                    </h1>
                    <p class="text-xs text-ink-muted">
                        {{ t('admin_v2.dashboard.description') }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    >
                        <Download class="size-3.5" />
                        {{ t('admin_v2.dashboard.export') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        @click="refresh"
                    >
                        <RefreshCw class="size-3.5" />
                        {{ t('admin_v2.dashboard.refresh') }}
                    </button>
                </div>
            </header>

            <!-- 5-stat row -->
            <section
                v-if="stats"
                class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5"
            >
                <StatCard
                    :value="stats.ticketsResolved.display ?? String(stats.ticketsResolved.value)"
                    :label="t('admin_v2.dashboard.stats.tickets_resolved')"
                    :delta="stats.ticketsResolved.delta"
                    :delta-dir="stats.ticketsResolved.deltaDir"
                    :series="stats.ticketsResolved.series"
                    :icon="CheckCircle2"
                    tint="var(--sp-success)"
                />
                <StatCard
                    :value="stats.avgHandleTime.display ?? String(stats.avgHandleTime.value)"
                    :label="t('admin_v2.dashboard.stats.avg_handle_time')"
                    :caption="stats.avgHandleTime.caption"
                    :delta="stats.avgHandleTime.delta"
                    :delta-dir="stats.avgHandleTime.deltaDir"
                    :series="stats.avgHandleTime.series"
                    :icon="Clock"
                    tint="var(--sp-accent-blue)"
                />
                <StatCard
                    :value="stats.tokensUsed.display ?? String(stats.tokensUsed.value)"
                    :label="t('admin_v2.dashboard.stats.tokens_used')"
                    :delta="stats.tokensUsed.delta"
                    :delta-dir="stats.tokensUsed.deltaDir"
                    :series="stats.tokensUsed.series"
                    :icon="Sparkles"
                    tint="var(--sp-spectrum-magenta)"
                />
                <StatCard
                    :value="stats.spendToday.display ?? String(stats.spendToday.value)"
                    :label="t('admin_v2.dashboard.stats.spend_today')"
                    :caption="stats.spendToday.caption"
                    :delta="stats.spendToday.delta"
                    :delta-dir="stats.spendToday.deltaDir"
                    :series="stats.spendToday.series"
                    :icon="TrendingUp"
                    tint="var(--sp-accent-cyan)"
                />
                <StatCard
                    :value="stats.totalUsers.display ?? String(stats.totalUsers.value)"
                    :label="t('admin_v2.dashboard.stats.total_users')"
                    :caption="stats.totalUsers.caption"
                    :icon="User"
                    tint="var(--sp-accent-blue)"
                />
            </section>

            <!-- Three Layers + System Health row (2/3 + 1/3 split on xl). -->
            <section class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <!-- Three Layers card — spans 2 columns on xl. -->
                <div
                    v-if="layers"
                    class="rounded-sp-sm border border-soft bg-navy p-5 xl:col-span-2"
                >
                    <header class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-ink">
                                {{ t('admin_v2.dashboard.layers.heading') }}
                            </h2>
                            <p class="text-xs text-ink-muted">
                                {{ t('admin_v2.dashboard.layers.subtitle') }}
                            </p>
                        </div>
                        <span
                            class="inline-flex shrink-0 items-center gap-1 rounded-pill border border-soft bg-white/5 px-2.5 py-1 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            <Zap class="size-3 text-sp-success" />
                            {{ t('admin_v2.dashboard.layers.live') }}
                        </span>
                    </header>

                    <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div
                            v-for="(card, idx) in layerCards"
                            :key="card.key"
                            :class="[
                                'rounded-xs p-4 transition-all',
                                idx === 2
                                    ? 'border bg-white/[0.02]'
                                    : '',
                            ]"
                            :style="
                                idx === 2
                                    ? {
                                          borderColor: `color-mix(in oklab, ${card.tint} 35%, transparent)`,
                                      }
                                    : {}
                            "
                        >
                            <p
                                class="flex items-center gap-1.5 text-[10px] font-semibold tracking-wider uppercase"
                                :style="{ color: card.tint }"
                            >
                                <span
                                    class="inline-block size-1.5 rounded-pill"
                                    :style="{ backgroundColor: card.tint }"
                                />
                                {{ card.label }}
                            </p>
                            <p class="mt-2 font-mono text-[24px] font-semibold text-ink">
                                {{ card.count.toLocaleString() }}
                            </p>
                            <p class="mt-1 text-xs text-ink-muted">
                                {{ card.subtitle }}
                            </p>
                            <div class="mt-4 h-10">
                                <Sparkline :series="card.series" :tint="card.tint" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System health card. -->
                <div class="rounded-sp-sm border border-soft bg-navy p-5">
                    <header class="flex items-start justify-between gap-3">
                        <h2 class="text-base font-semibold text-ink">
                            {{ t('admin_v2.dashboard.health.heading') }}
                        </h2>
                        <span
                            v-if="allSystemsGo"
                            class="inline-flex shrink-0 items-center gap-1 rounded-pill bg-sp-success/15 px-2.5 py-1 text-[10px] font-semibold tracking-wider text-sp-success uppercase"
                        >
                            <CheckCircle2 class="size-3" />
                            {{ t('admin_v2.dashboard.health.all_go') }}
                        </span>
                        <span
                            v-else
                            class="inline-flex shrink-0 items-center gap-1 rounded-pill bg-sp-warning/15 px-2.5 py-1 text-[10px] font-semibold tracking-wider text-sp-warning uppercase"
                        >
                            {{ t('admin_v2.dashboard.health.issues') }}
                        </span>
                    </header>

                    <p
                        v-if="health.length === 0"
                        class="px-2 py-6 text-sm text-ink-muted"
                    >
                        {{ t('admin_v2.dashboard.health.empty') }}
                    </p>
                    <div v-else class="mt-3 divide-y divide-soft">
                        <HealthRow
                            v-for="check in health"
                            :key="check.id"
                            :check="check"
                        />
                    </div>
                </div>
            </section>

            <!-- Spend by provider + Recent admin activity row. -->
            <section class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <!-- Spend by provider. -->
                <div class="rounded-sp-sm border border-soft bg-navy p-5">
                    <header class="flex items-start justify-between gap-3">
                        <h2 class="text-base font-semibold text-ink">
                            {{ t('admin_v2.dashboard.spend.heading') }}
                        </h2>
                        <span
                            class="inline-flex shrink-0 items-center rounded-pill border border-soft bg-white/5 px-2.5 py-1 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            {{ t('admin_v2.dashboard.spend.range') }}
                        </span>
                    </header>

                    <div v-if="spend && spendSegments.length" class="mt-5 space-y-5">
                        <!-- Stacked horizontal bar. -->
                        <div class="flex h-2 overflow-hidden rounded-pill">
                            <div
                                v-for="seg in spendSegments"
                                :key="seg.name"
                                class="h-full"
                                :style="{
                                    width: `${seg.pct}%`,
                                    backgroundColor: seg.color,
                                }"
                            />
                        </div>

                        <!-- Table: dot + name · calls · cost -->
                        <ul class="space-y-2.5 text-sm">
                            <li
                                v-for="p in spend.providers"
                                :key="p.name"
                                class="grid grid-cols-[auto_1fr_auto_auto] items-center gap-3"
                            >
                                <span
                                    class="size-2 rounded-pill"
                                    :style="{ backgroundColor: p.color }"
                                />
                                <span class="text-ink">{{ p.name }}</span>
                                <span class="text-right text-xs text-ink-muted">
                                    {{ p.calls.toLocaleString() }}
                                    {{ t('admin_v2.dashboard.spend.calls') }}
                                </span>
                                <span class="text-right font-mono text-sm text-ink">
                                    ${{ p.cost.toFixed(2) }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <p v-else class="px-2 py-6 text-sm text-ink-muted">
                        {{ t('admin_v2.dashboard.spend.empty') }}
                    </p>
                </div>

                <!-- Recent admin activity. -->
                <div class="rounded-sp-sm border border-soft bg-navy p-5">
                    <header class="flex items-start justify-between gap-3">
                        <h2 class="text-base font-semibold text-ink">
                            {{ t('admin_v2.dashboard.audit.heading') }}
                        </h2>
                        <Link
                            href="/admin2/users"
                            class="text-xs text-ink-muted hover:text-ink"
                        >
                            {{ t('admin_v2.dashboard.audit.view_all') }}
                        </Link>
                    </header>

                    <p
                        v-if="audit.length === 0"
                        class="px-2 py-6 text-sm text-ink-muted"
                    >
                        {{ t('admin_v2.dashboard.audit.empty') }}
                    </p>
                    <div v-else class="mt-3 divide-y divide-soft">
                        <AuditRow
                            v-for="entry in audit"
                            :key="entry.id"
                            :entry="entry"
                        />
                    </div>
                </div>
            </section>
        </div>
    </AdminV2Layout>
</template>
