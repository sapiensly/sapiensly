<script setup lang="ts">
import { Coins, Gauge, Hash, Timer } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    fmtCostValue,
    fmtInt,
    fmtMs,
    fmtPct,
    type RunMetrics,
} from './metrics';

const props = withDefaults(
    defineProps<{ metrics: RunMetrics; detailed?: boolean }>(),
    { detailed: false },
);

const { t } = useI18n();

/** Token breakdown rows (input / output / cache / total), skipping null lines. */
const tokenRows = computed(() => {
    const e = props.metrics.efficiency;
    return [
        {
            key: 'input',
            label: t('app_v2.playground.metrics_input'),
            n: e.prompt_tokens,
        },
        {
            key: 'output',
            label: t('app_v2.playground.metrics_output'),
            n: e.completion_tokens,
        },
        {
            key: 'cache',
            label: t('app_v2.playground.metrics_cache'),
            n: e.cached_prompt_tokens,
        },
        {
            key: 'total',
            label: t('app_v2.playground.metrics_total'),
            n: e.total_tokens,
            strong: true,
        },
    ].filter((r) => r.n != null);
});

/** Cost breakdown rows (input / output / cache / total), skipping null lines. */
const costRows = computed(() => {
    const c = props.metrics.cost;
    return [
        {
            key: 'input',
            label: t('app_v2.playground.metrics_input'),
            v: c.input,
        },
        {
            key: 'output',
            label: t('app_v2.playground.metrics_output'),
            v: c.output,
        },
        {
            key: 'cache',
            label: t('app_v2.playground.metrics_cache'),
            v: c.cached,
        },
        {
            key: 'total',
            label: t('app_v2.playground.metrics_total'),
            v: c.total,
            strong: true,
        },
    ].filter((r) => r.v != null);
});

const hasBreakdown = computed(
    () => tokenRows.value.length > 0 || costRows.value.length > 0,
);

/**
 * The latency split bar: queue wait vs provider execution vs job overhead as a
 * parts-of-a-whole meter. Each hue is paired with a labelled legend entry, so
 * identity never rests on colour alone. Only segments with real time appear.
 */
const segments = computed(() => {
    const l = props.metrics.latency;
    return [
        {
            key: 'queue',
            label: t('app_v2.playground.metrics_queue'),
            ms: l.queue_wait_ms ?? 0,
            bar: 'bg-sp-warning',
            dot: 'bg-sp-warning',
        },
        {
            key: 'execution',
            label: t('app_v2.playground.metrics_execution'),
            ms: l.execution_ms ?? 0,
            bar: 'bg-accent-blue',
            dot: 'bg-accent-blue',
        },
        {
            key: 'overhead',
            label: t('app_v2.playground.metrics_overhead'),
            ms: l.job_overhead_ms ?? 0,
            bar: 'bg-ink-faint',
            dot: 'bg-ink-faint',
        },
    ].filter((s) => s.ms > 0);
});

const totalMs = computed(
    () =>
        props.metrics.latency.end_to_end_ms ??
        segments.value.reduce((sum, s) => sum + s.ms, 0),
);

const hasBar = computed(() => totalMs.value > 0 && segments.value.length > 0);

function widthPct(ms: number): string {
    const denom = segments.value.reduce((sum, s) => sum + s.ms, 0) || 1;
    return `${(ms / denom) * 100}%`;
}
</script>

<template>
    <div class="space-y-3">
        <!-- Headline KPIs: the four figures that read a run's performance at a
             glance — speed, throughput, price, size. -->
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-xl border border-soft bg-surface px-3 py-2">
                <div class="flex items-center gap-1.5 text-ink-subtle">
                    <Timer class="size-3.5" />
                    <span
                        class="text-[10px] font-medium tracking-wide uppercase"
                    >
                        {{ t('app_v2.playground.metrics_execution') }}
                    </span>
                </div>
                <p class="mt-0.5 text-lg font-semibold text-ink tabular-nums">
                    {{ fmtMs(metrics.latency.execution_ms) }}
                </p>
            </div>

            <div class="rounded-xl border border-soft bg-surface px-3 py-2">
                <div class="flex items-center gap-1.5 text-ink-subtle">
                    <Gauge class="size-3.5" />
                    <span
                        class="text-[10px] font-medium tracking-wide uppercase"
                    >
                        {{ t('app_v2.playground.metrics_throughput') }}
                    </span>
                </div>
                <p class="mt-0.5 text-lg font-semibold text-ink tabular-nums">
                    {{ metrics.latency.output_tokens_per_second ?? '—' }}
                    <span
                        v-if="metrics.latency.output_tokens_per_second != null"
                        class="text-xs font-normal text-ink-muted"
                    >
                        {{ t('app_v2.playground.metrics_tps') }}
                    </span>
                </p>
            </div>

            <div class="rounded-xl border border-soft bg-surface px-3 py-2">
                <div class="flex items-center gap-1.5 text-ink-subtle">
                    <Coins class="size-3.5" />
                    <span
                        class="text-[10px] font-medium tracking-wide uppercase"
                    >
                        {{ t('app_v2.playground.metrics_cost') }}
                    </span>
                </div>
                <p class="mt-0.5 text-lg font-semibold text-ink tabular-nums">
                    {{ fmtCostValue(metrics.cost.total) }}
                    <span
                        v-if="
                            metrics.cost.estimated && metrics.cost.total != null
                        "
                        :title="t('app_v2.playground.metrics_estimated')"
                        class="text-xs font-normal text-ink-muted"
                        >~</span
                    >
                </p>
            </div>

            <div class="rounded-xl border border-soft bg-surface px-3 py-2">
                <div class="flex items-center gap-1.5 text-ink-subtle">
                    <Hash class="size-3.5" />
                    <span
                        class="text-[10px] font-medium tracking-wide uppercase"
                    >
                        {{ t('app_v2.playground.metrics_tokens') }}
                    </span>
                </div>
                <p class="mt-0.5 text-lg font-semibold text-ink tabular-nums">
                    {{ fmtInt(metrics.efficiency.total_tokens) }}
                </p>
            </div>
        </div>

        <!-- Latency composition: where the wall-clock time went. -->
        <div v-if="hasBar" class="space-y-1.5">
            <div
                class="flex items-center justify-between text-[11px] text-ink-subtle"
            >
                <span class="font-medium tracking-wide uppercase">
                    {{ t('app_v2.playground.metrics_total_latency') }}
                </span>
                <span class="flex items-center gap-3 tabular-nums">
                    <span
                        v-if="metrics.latency.ttft_ms != null"
                        class="text-ink-muted"
                        :title="t('app_v2.playground.metrics_ttft_hint')"
                    >
                        {{ t('app_v2.playground.metrics_ttft') }}
                        {{ fmtMs(metrics.latency.ttft_ms) }}
                    </span>
                    <span class="text-ink-muted">{{ fmtMs(totalMs) }}</span>
                </span>
            </div>
            <div class="flex h-2.5 gap-[2px] overflow-hidden rounded-full">
                <div
                    v-for="s in segments"
                    :key="s.key"
                    class="h-full first:rounded-l-full last:rounded-r-full"
                    :class="s.bar"
                    :style="{ width: widthPct(s.ms) }"
                    :title="`${s.label}: ${fmtMs(s.ms)}`"
                />
            </div>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                <span
                    v-for="s in segments"
                    :key="s.key"
                    class="inline-flex items-center gap-1.5 text-ink-muted"
                >
                    <span class="size-2 rounded-full" :class="s.dot" />
                    {{ s.label }}
                    <span class="text-ink-subtle tabular-nums">{{
                        fmtMs(s.ms)
                    }}</span>
                </span>
            </div>
        </div>

        <!-- Secondary efficiency signals — only when the provider reports them. -->
        <div
            v-if="
                metrics.efficiency.reasoning_ratio != null ||
                metrics.efficiency.cached_prompt_ratio != null ||
                metrics.cost.per_1k_tokens != null
            "
            class="flex flex-wrap gap-1.5"
        >
            <span
                v-if="metrics.efficiency.reasoning_ratio != null"
                class="inline-flex items-center gap-1 rounded-full border border-soft px-2 py-0.5 text-[11px] text-ink-muted"
                :title="t('app_v2.playground.metrics_reasoning_hint')"
            >
                {{ t('app_v2.playground.metrics_reasoning') }}
                <span class="text-ink tabular-nums">{{
                    fmtPct(metrics.efficiency.reasoning_ratio)
                }}</span>
            </span>
            <span
                v-if="metrics.efficiency.cached_prompt_ratio != null"
                class="inline-flex items-center gap-1 rounded-full border border-soft px-2 py-0.5 text-[11px] text-ink-muted"
                :title="t('app_v2.playground.metrics_cache_hint')"
            >
                {{ t('app_v2.playground.metrics_cache') }}
                <span class="text-ink tabular-nums">{{
                    fmtPct(metrics.efficiency.cached_prompt_ratio)
                }}</span>
            </span>
            <span
                v-if="metrics.cost.per_1k_tokens != null"
                class="inline-flex items-center gap-1 rounded-full border border-soft px-2 py-0.5 text-[11px] text-ink-muted"
            >
                {{ t('app_v2.playground.metrics_per_1k') }}
                <span class="text-ink tabular-nums">{{
                    fmtCostValue(metrics.cost.per_1k_tokens)
                }}</span>
            </span>
        </div>

        <!-- Token & cost breakdown (input / output / cache) — detailed view. -->
        <div v-if="detailed && hasBreakdown" class="grid gap-2 sm:grid-cols-2">
            <div
                v-if="tokenRows.length"
                class="rounded-xl border border-soft bg-surface p-3"
            >
                <p
                    class="mb-1.5 text-[10px] font-medium tracking-wide text-ink-subtle uppercase"
                >
                    {{ t('app_v2.playground.metrics_tokens_breakdown') }}
                </p>
                <div class="space-y-1">
                    <div
                        v-for="r in tokenRows"
                        :key="r.key"
                        class="flex items-center justify-between text-xs"
                        :class="
                            r.strong
                                ? 'border-t border-soft pt-1 font-medium text-ink'
                                : 'text-ink-muted'
                        "
                    >
                        <span>{{ r.label }}</span>
                        <span class="tabular-nums">{{ fmtInt(r.n) }}</span>
                    </div>
                </div>
            </div>

            <div
                v-if="costRows.length"
                class="rounded-xl border border-soft bg-surface p-3"
            >
                <p
                    class="mb-1.5 text-[10px] font-medium tracking-wide text-ink-subtle uppercase"
                >
                    {{ t('app_v2.playground.metrics_cost_breakdown') }}
                </p>
                <div class="space-y-1">
                    <div
                        v-for="r in costRows"
                        :key="r.key"
                        class="flex items-center justify-between text-xs"
                        :class="
                            r.strong
                                ? 'border-t border-soft pt-1 font-medium text-ink'
                                : 'text-ink-muted'
                        "
                    >
                        <span>{{ r.label }}</span>
                        <span class="tabular-nums">{{
                            fmtCostValue(r.v)
                        }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
