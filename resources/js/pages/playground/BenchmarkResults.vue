<script setup lang="ts">
import { normalizeChatMarkdown } from '@/lib/markdown';
import { Check, Coins, Copy, Gauge, Timer, Trophy, Zap } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    modelColor,
    type BenchmarkDetail,
    type BenchmarkModelEntry,
} from './benchmark';
import { fmtCostValue, fmtInt, fmtMs, fmtPct } from './metrics';

const props = defineProps<{ detail: BenchmarkDetail }>();

const emit = defineEmits<{
    (e: 'winner', runId: string, note: string): void;
}>();

const { t } = useI18n();

// ── Identity ─────────────────────────────────────────────────────────
const copiedId = ref(false);
async function copyBenchmarkId() {
    try {
        await navigator.clipboard.writeText(props.detail.id);
        copiedId.value = true;
        setTimeout(() => (copiedId.value = false), 1500);
    } catch {
        /* clipboard unavailable */
    }
}

/** Stable per-model color index, by order of appearance in the comparison. */
const colorOf = computed(() => {
    const map: Record<string, string> = {};
    props.detail.comparison.models.forEach((m, i) => {
        map[m.model] = modelColor(i);
    });
    return map;
});

const okModels = computed(() =>
    props.detail.comparison.models.filter((m) => m.status === 'ok'),
);

// ── Verdict cards ────────────────────────────────────────────────────
const verdictCards = computed(() => {
    const v = props.detail.comparison.verdicts;
    return [
        {
            key: 'fastest',
            icon: Timer,
            label: t('app_v2.playground.bench_fastest'),
            hint: t('app_v2.playground.bench_fastest_hint'),
            verdict: v.fastest_execution,
            fmt: (n: number) => fmtMs(n),
        },
        {
            key: 'ttft',
            icon: Zap,
            label: t('app_v2.playground.bench_ttft'),
            hint: t('app_v2.playground.bench_ttft_hint'),
            verdict: v.best_ttft,
            fmt: (n: number) => fmtMs(n),
        },
        {
            key: 'cheapest',
            icon: Coins,
            label: t('app_v2.playground.bench_cheapest'),
            hint: t('app_v2.playground.bench_cheapest_hint'),
            verdict: v.cheapest,
            fmt: (n: number) => fmtCostValue(n),
        },
        {
            key: 'throughput',
            icon: Gauge,
            label: t('app_v2.playground.bench_throughput'),
            hint: t('app_v2.playground.bench_throughput_hint'),
            verdict: v.highest_throughput,
            fmt: (n: number) => `${n} ${t('app_v2.playground.metrics_tps')}`,
        },
    ].filter((c) => c.verdict !== null);
});

// ── Comparison table ─────────────────────────────────────────────────
type MetricKey = keyof BenchmarkModelEntry['metrics'];
interface Row {
    key: MetricKey;
    label: string;
    fmt: (n: number) => string;
    better: 'low' | 'high' | 'none';
}

const rows = computed<Row[]>(() => [
    {
        key: 'execution_ms',
        label: t('app_v2.playground.metrics_execution'),
        fmt: fmtMs,
        better: 'low',
    },
    {
        key: 'ttft_ms',
        label: t('app_v2.playground.metrics_ttft'),
        fmt: fmtMs,
        better: 'low',
    },
    {
        key: 'output_tokens_per_second',
        label: t('app_v2.playground.metrics_throughput'),
        fmt: (n) => `${n} ${t('app_v2.playground.metrics_tps')}`,
        better: 'high',
    },
    {
        key: 'cost',
        label: t('app_v2.playground.metrics_cost'),
        fmt: fmtCostValue,
        better: 'low',
    },
    {
        key: 'per_1k_tokens',
        label: t('app_v2.playground.metrics_per_1k'),
        fmt: fmtCostValue,
        better: 'low',
    },
    {
        key: 'completion_tokens',
        label: t('app_v2.playground.metrics_output'),
        fmt: fmtInt,
        better: 'none',
    },
    {
        key: 'reasoning_ratio',
        label: t('app_v2.playground.metrics_reasoning'),
        fmt: fmtPct,
        better: 'none',
    },
    {
        key: 'cached_prompt_ratio',
        label: t('app_v2.playground.metrics_cache'),
        fmt: fmtPct,
        better: 'none',
    },
]);

const visibleRows = computed(() =>
    rows.value.filter((row) =>
        props.detail.comparison.models.some((m) => m.metrics[row.key] !== null),
    ),
);

function bestValue(row: Row): number | null {
    if (row.better === 'none') return null;
    const values = okModels.value
        .map((m) => m.metrics[row.key])
        .filter((v): v is number => v !== null);
    if (values.length < 2) return null;
    return row.better === 'low' ? Math.min(...values) : Math.max(...values);
}

function isBest(row: Row, m: BenchmarkModelEntry): boolean {
    const best = bestValue(row);
    return best !== null && m.metrics[row.key] === best;
}

/** Relative multiplier vs the best (e.g. "2.3×") — shown when meaningfully worse. */
function delta(row: Row, m: BenchmarkModelEntry): string | null {
    const best = bestValue(row);
    const value = m.metrics[row.key];
    if (best === null || value === null || best <= 0 || value === best)
        return null;
    const ratio = row.better === 'low' ? value / best : best / value;
    return ratio >= 1.05 ? `${ratio.toFixed(1)}×` : null;
}

// ── Cost × speed scatter ─────────────────────────────────────────────
const W = 340;
const H = 210;
const PAD = { left: 42, right: 16, top: 14, bottom: 34 };

const scatterPoints = computed(() => {
    const points = okModels.value
        .filter(
            (m) => m.metrics.cost !== null && m.metrics.execution_ms !== null,
        )
        .map((m) => ({
            model: m.model,
            cost: m.metrics.cost!,
            ms: m.metrics.execution_ms!,
            color: colorOf.value[m.model],
        }));
    if (points.length < 2) return [];

    const maxCost = Math.max(...points.map((p) => p.cost)) * 1.15 || 1;
    const maxMs = Math.max(...points.map((p) => p.ms)) * 1.15 || 1;

    return points.map((p) => ({
        ...p,
        x: PAD.left + (p.cost / maxCost) * (W - PAD.left - PAD.right),
        y: H - PAD.bottom - (p.ms / maxMs) * (H - PAD.top - PAD.bottom),
    }));
});

// ── Winner selection ─────────────────────────────────────────────────
const choosing = ref<string | null>(null);
const note = ref('');

function winnerRunFor(m: BenchmarkModelEntry): string | null {
    const okRun = props.detail.runs.find(
        (r) => r.model === m.model && r.status === 'ok',
    );
    return okRun?.id ?? m.run_ids[0] ?? null;
}

function confirmWinner(m: BenchmarkModelEntry) {
    const runId = winnerRunFor(m);
    if (runId) emit('winner', runId, note.value);
    choosing.value = null;
    note.value = '';
}

function isWinner(m: BenchmarkModelEntry): boolean {
    const w = props.detail.comparison.winner;
    return w !== null && m.run_ids.includes(w.run_id);
}

// ── Answers ──────────────────────────────────────────────────────────
function renderMarkdown(content: string | null): string {
    if (!content) return '';
    const raw = marked.parse(normalizeChatMarkdown(content), {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}

function answerFor(m: BenchmarkModelEntry): string | null {
    return (
        props.detail.runs.find((r) => r.model === m.model && r.status === 'ok')
            ?.output_text ?? null
    );
}
</script>

<template>
    <div class="space-y-5">
        <!-- Benchmark identity: the auditable pgbench_... id. -->
        <button
            type="button"
            class="inline-flex items-center gap-1 font-mono text-[11px] text-ink-subtle transition-colors hover:text-ink"
            :title="t('app_v2.playground.copy')"
            @click="copyBenchmarkId"
        >
            <Check v-if="copiedId" class="size-3 text-sp-success" />
            <Copy v-else class="size-3" />
            {{ detail.id }}
        </button>

        <!-- 1. Verdicts: who wins each dimension, and for which situation. -->
        <div
            v-if="verdictCards.length"
            class="grid grid-cols-2 gap-2 xl:grid-cols-4"
        >
            <div
                v-for="card in verdictCards"
                :key="card.key"
                class="rounded-xl border border-soft bg-surface px-3 py-2.5"
            >
                <div class="flex items-center gap-1.5 text-ink-subtle">
                    <component :is="card.icon" class="size-3.5" />
                    <span
                        class="text-[10px] font-medium tracking-wide uppercase"
                    >
                        {{ card.label }}
                    </span>
                </div>
                <p
                    class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-ink"
                >
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :style="{ background: colorOf[card.verdict!.model] }"
                    />
                    <span class="truncate">{{ card.verdict!.model }}</span>
                </p>
                <p class="text-xs text-ink-muted tabular-nums">
                    {{ card.fmt(card.verdict!.value) }}
                </p>
                <p class="mt-0.5 text-[10px] text-ink-subtle">
                    {{ card.hint }}
                </p>
            </div>
        </div>

        <!-- 2. Metric table: best per row marked, deltas vs the best. -->
        <div class="overflow-x-auto rounded-xl border border-soft">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-soft text-left">
                        <th
                            class="p-2.5 text-[11px] font-medium text-ink-subtle uppercase"
                        ></th>
                        <th
                            v-for="m in detail.comparison.models"
                            :key="m.model"
                            class="p-2.5 font-medium text-ink"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :style="{ background: colorOf[m.model] }"
                                />
                                <span class="max-w-[16ch] truncate">{{
                                    m.model
                                }}</span>
                                <Trophy
                                    v-if="isWinner(m)"
                                    class="size-3.5 text-sp-warning"
                                />
                            </span>
                            <span
                                v-if="m.status === 'error'"
                                class="block text-[10px] font-normal text-sp-danger"
                            >
                                {{ t('app_v2.playground.bench_failed') }}
                            </span>
                            <span
                                v-else-if="m.status === 'running'"
                                class="block animate-pulse text-[10px] font-normal text-sp-warning"
                            >
                                {{ t('app_v2.playground.running') }}
                            </span>
                            <span
                                v-else-if="m.served_by"
                                class="block text-[10px] font-normal text-ink-subtle"
                            >
                                {{ m.served_by }}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in visibleRows"
                        :key="row.key"
                        class="border-b border-soft last:border-0"
                    >
                        <td
                            class="p-2.5 text-[11px] font-medium whitespace-nowrap text-ink-subtle uppercase"
                        >
                            {{ row.label }}
                        </td>
                        <td
                            v-for="m in detail.comparison.models"
                            :key="m.model"
                            class="p-2.5 tabular-nums"
                            :class="
                                isBest(row, m)
                                    ? 'font-semibold text-sp-success'
                                    : 'text-ink'
                            "
                        >
                            <template v-if="m.metrics[row.key] !== null">
                                {{ row.fmt(m.metrics[row.key]!) }}
                                <Check
                                    v-if="isBest(row, m)"
                                    class="inline size-3"
                                />
                                <span
                                    v-else-if="delta(row, m)"
                                    class="text-[11px] text-ink-subtle"
                                >
                                    {{ delta(row, m) }}
                                </span>
                            </template>
                            <span v-else class="text-ink-faint">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 3. The trade-off chart: cost × execution time, one dot per model.
             Bottom-left = cheap and fast. -->
        <div
            v-if="scatterPoints.length >= 2"
            class="rounded-xl border border-soft bg-surface p-3"
        >
            <p class="mb-1 text-[11px] font-medium text-ink-subtle uppercase">
                {{ t('app_v2.playground.bench_scatter_title') }}
            </p>
            <svg
                :viewBox="`0 0 ${W} ${H}`"
                class="w-full max-w-md text-ink-subtle"
                role="img"
            >
                <!-- Recessive axes. -->
                <line
                    :x1="PAD.left"
                    :y1="H - PAD.bottom"
                    :x2="W - PAD.right"
                    :y2="H - PAD.bottom"
                    stroke="currentColor"
                    stroke-opacity="0.25"
                />
                <line
                    :x1="PAD.left"
                    :y1="PAD.top"
                    :x2="PAD.left"
                    :y2="H - PAD.bottom"
                    stroke="currentColor"
                    stroke-opacity="0.25"
                />
                <text
                    :x="W - PAD.right"
                    :y="H - 10"
                    text-anchor="end"
                    class="fill-current text-[9px]"
                >
                    {{ t('app_v2.playground.bench_scatter_x') }} →
                </text>
                <text
                    :x="PAD.left - 30"
                    :y="PAD.top + 4"
                    class="fill-current text-[9px]"
                >
                    ↑ {{ t('app_v2.playground.bench_scatter_y') }}
                </text>
                <!-- Sweet spot: cheap and fast. -->
                <text
                    :x="PAD.left + 6"
                    :y="H - PAD.bottom - 6"
                    class="fill-current text-[9px] opacity-70"
                >
                    ✓ {{ t('app_v2.playground.bench_sweet_spot') }}
                </text>
                <g v-for="p in scatterPoints" :key="p.model">
                    <circle
                        :cx="p.x"
                        :cy="p.y"
                        r="5.5"
                        :fill="p.color"
                        stroke="var(--sp-bg-secondary)"
                        stroke-width="2"
                    >
                        <title>
                            {{ p.model }}: {{ fmtCostValue(p.cost) }} ·
                            {{ fmtMs(p.ms) }}
                        </title>
                    </circle>
                    <text
                        :x="p.x + 8"
                        :y="p.y + 3"
                        class="fill-current text-[9px]"
                    >
                        {{ p.model }}
                    </text>
                </g>
            </svg>
        </div>

        <!-- 4. The answers, side by side — quality is the metric numbers miss. -->
        <div class="grid gap-3 lg:grid-cols-2 2xl:grid-cols-3">
            <div
                v-for="m in detail.comparison.models"
                :key="m.model"
                class="flex flex-col rounded-xl border bg-surface"
                :class="isWinner(m) ? 'border-sp-warning/60' : 'border-soft'"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-soft px-3 py-2"
                >
                    <span
                        class="inline-flex min-w-0 items-center gap-1.5 text-sm font-medium text-ink"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{ background: colorOf[m.model] }"
                        />
                        <span class="truncate">{{ m.model }}</span>
                        <Trophy
                            v-if="isWinner(m)"
                            class="size-3.5 shrink-0 text-sp-warning"
                        />
                    </span>
                    <button
                        v-if="!isWinner(m) && m.status === 'ok'"
                        type="button"
                        class="shrink-0 rounded-full border border-soft px-2 py-0.5 text-[11px] text-ink-muted transition-colors hover:border-medium hover:text-ink"
                        @click="
                            choosing = choosing === m.model ? null : m.model
                        "
                    >
                        {{ t('app_v2.playground.bench_pick_winner') }}
                    </button>
                </div>

                <!-- Inline decision note before confirming a winner. -->
                <div
                    v-if="choosing === m.model"
                    class="space-y-2 border-b border-soft px-3 py-2"
                >
                    <input
                        v-model="note"
                        type="text"
                        :placeholder="
                            t('app_v2.playground.bench_note_placeholder')
                        "
                        class="w-full rounded-lg border border-soft bg-transparent px-2 py-1 text-xs text-ink placeholder:text-ink-faint focus:border-medium focus:outline-none"
                        @keyup.enter="confirmWinner(m)"
                    />
                    <button
                        type="button"
                        class="rounded-full bg-accent-blue px-2.5 py-0.5 text-[11px] font-medium text-white transition-colors hover:bg-accent-blue-hover"
                        @click="confirmWinner(m)"
                    >
                        {{ t('app_v2.playground.bench_confirm_winner') }}
                    </button>
                </div>

                <div
                    v-if="m.status === 'error'"
                    class="p-3 text-xs text-sp-danger"
                >
                    {{ m.error }}
                </div>
                <div
                    v-else-if="answerFor(m)"
                    class="sp-chat-prose prose prose-sm max-h-80 max-w-none overflow-auto p-3 text-sm text-ink dark:prose-invert"
                    v-html="renderMarkdown(answerFor(m))"
                />
                <p
                    v-else
                    class="inline-flex items-center gap-2 p-3 text-xs text-ink-muted"
                >
                    <span
                        class="size-2 animate-pulse rounded-full bg-sp-warning"
                    />
                    {{ t('app_v2.playground.running') }}
                </p>
            </div>
        </div>

        <!-- Recorded decision. -->
        <p
            v-if="detail.comparison.winner?.note"
            class="rounded-xl border border-soft bg-surface px-3 py-2 text-xs text-ink-muted"
        >
            <Trophy class="mr-1 inline size-3.5 text-sp-warning" />
            <span class="font-medium text-ink">{{
                detail.comparison.winner.model
            }}</span>
            — {{ detail.comparison.winner.note }}
        </p>
    </div>
</template>
