<script setup lang="ts">
import { Check, ChevronDown, Loader2, Play } from '@lucide/vue';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BenchmarkResults from './BenchmarkResults.vue';
import type { BenchmarkDetail, BenchmarkListItem } from './benchmark';

interface CapabilityModel {
    id: string | number;
    driver: string;
    name: string;
    label: string;
}

const props = defineProps<{
    capability: string;
    models: CapabilityModel[];
}>();

const { t } = useI18n();

// ── Form state ───────────────────────────────────────────────────────
const prompt = ref('');
const selectedIds = ref<Array<string | number>>([]);
const repeats = ref(1);
const running = ref(false);
const error = ref<string | null>(null);
const detail = ref<BenchmarkDetail | null>(null);

function toggleModel(id: string | number) {
    const index = selectedIds.value.indexOf(id);
    if (index >= 0) {
        selectedIds.value.splice(index, 1);
    } else if (selectedIds.value.length < 6) {
        selectedIds.value.push(id);
    }
}

const canRun = computed(
    () =>
        !running.value &&
        prompt.value.trim() !== '' &&
        selectedIds.value.length >= 2,
);

// Switching capability (text ↔ coding) resets the selection: the model list
// is the same catalog, but a fresh comparison is a fresh decision.
watch(
    () => props.capability,
    () => {
        detail.value = null;
        error.value = null;
    },
);

// ── Run + poll ───────────────────────────────────────────────────────
const POLL_INTERVAL_MS = 1500;
const POLL_TIMEOUT_MS = 10 * 60 * 1000;

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function loadDetail(id: string): Promise<BenchmarkDetail> {
    const { data } = await axios.get<BenchmarkDetail>(
        `/playground/benchmark/${id}`,
    );
    return data;
}

async function run() {
    if (!canRun.value) return;
    running.value = true;
    error.value = null;
    detail.value = null;

    try {
        const { data } = await axios.post('/playground/benchmark', {
            capability: props.capability,
            prompt: prompt.value,
            model_ids: selectedIds.value,
            repeats: repeats.value,
        });

        // Poll — partial results render as each member run lands.
        const startedAt = Date.now();
        for (;;) {
            detail.value = await loadDetail(data.benchmark_id);
            if (detail.value.comparison.status === 'complete') break;
            if (Date.now() - startedAt > POLL_TIMEOUT_MS) break;
            await sleep(POLL_INTERVAL_MS);
        }
    } catch (err) {
        const e = err as { response?: { data?: { error?: string } } };
        error.value = e.response?.data?.error ?? t('app_v2.playground.error');
    } finally {
        running.value = false;
        loadHistory(1);
    }
}

async function setWinner(runId: string, note: string) {
    if (!detail.value) return;
    try {
        await axios.post(`/playground/benchmark/${detail.value.id}/winner`, {
            run_id: runId,
            note: note || null,
        });
        detail.value = await loadDetail(detail.value.id);
        loadHistory(history.value.page);
    } catch {
        /* best-effort; the benchmark itself is untouched */
    }
}

// ── History ──────────────────────────────────────────────────────────
const history = ref({
    items: [] as BenchmarkListItem[],
    page: 1,
    lastPage: 1,
    loading: false,
});

async function loadHistory(page = 1) {
    history.value.loading = true;
    try {
        const { data } = await axios.get('/playground/benchmarks', {
            params: { page },
        });
        history.value.items = data.data;
        history.value.page = data.current_page;
        history.value.lastPage = data.last_page;
    } catch {
        /* history is best-effort */
    } finally {
        history.value.loading = false;
    }
}

async function openBenchmark(id: string) {
    try {
        detail.value = await loadDetail(id);
    } catch {
        /* row may have been deleted */
    }
}

onMounted(() => loadHistory(1));
</script>

<template>
    <div class="space-y-5">
        <!-- Form: one prompt, N models. -->
        <div class="space-y-3 rounded-2xl border border-soft bg-surface p-4">
            <textarea
                v-model="prompt"
                rows="3"
                :placeholder="t('app_v2.playground.bench_prompt_placeholder')"
                class="w-full resize-y rounded-xl border border-soft bg-transparent p-3 text-sm text-ink placeholder:text-ink-faint focus:border-medium focus:outline-none"
            />

            <div>
                <p
                    class="mb-1.5 text-[11px] font-medium text-ink-subtle uppercase"
                >
                    {{ t('app_v2.playground.bench_models') }}
                    <span class="normal-case">
                        ({{ selectedIds.length }}/6 ·
                        {{ t('app_v2.playground.bench_min_models') }})
                    </span>
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="m in models"
                        :key="m.id"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs transition-colors"
                        :class="
                            selectedIds.includes(m.id)
                                ? 'border-accent-blue/60 bg-accent-blue/10 text-ink'
                                : 'border-soft text-ink-muted hover:border-medium hover:text-ink'
                        "
                        @click="toggleModel(m.id)"
                    >
                        <Check
                            v-if="selectedIds.includes(m.id)"
                            class="size-3 text-accent-blue"
                        />
                        {{ m.label }}
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <label
                    class="inline-flex items-center gap-2 text-xs text-ink-muted"
                >
                    {{ t('app_v2.playground.bench_repeats') }}
                    <select
                        v-model.number="repeats"
                        class="rounded-lg border border-soft bg-transparent px-2 py-1 text-xs text-ink focus:outline-none"
                    >
                        <option :value="1">1</option>
                        <option :value="3">3</option>
                        <option :value="5">5</option>
                    </select>
                </label>

                <button
                    type="button"
                    :disabled="!canRun"
                    class="inline-flex items-center gap-2 rounded-full bg-accent-blue px-4 py-1.5 text-sm font-medium text-white transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-40"
                    @click="run"
                >
                    <Loader2 v-if="running" class="size-4 animate-spin" />
                    <Play v-else class="size-4" />
                    {{ t('app_v2.playground.bench_run') }}
                </button>
            </div>

            <p v-if="error" class="text-xs text-sp-danger">{{ error }}</p>
        </div>

        <!-- Results (fills progressively while polling). -->
        <BenchmarkResults v-if="detail" :detail="detail" @winner="setWinner" />

        <!-- Past benchmarks. -->
        <div
            v-if="history.items.length"
            class="rounded-2xl border border-soft bg-surface p-4"
        >
            <p class="mb-2 text-[11px] font-medium text-ink-subtle uppercase">
                {{ t('app_v2.playground.bench_history') }}
            </p>
            <ul class="divide-y divide-[var(--sp-border-soft)]">
                <li v-for="item in history.items" :key="item.id">
                    <button
                        type="button"
                        class="grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 py-2 text-left transition-colors hover:bg-surface-hover"
                        @click="openBenchmark(item.id)"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="
                                item.status === 'complete'
                                    ? 'bg-sp-success'
                                    : 'animate-pulse bg-sp-warning'
                            "
                        />
                        <span class="min-w-0">
                            <span class="block truncate text-sm text-ink">
                                {{ item.excerpt }}
                            </span>
                            <span
                                class="block truncate text-[11px] text-ink-subtle"
                            >
                                {{ item.models.join(' · ') }}
                                <template v-if="item.winner_model">
                                    · 🏆 {{ item.winner_model }}
                                </template>
                            </span>
                        </span>
                        <ChevronDown
                            class="size-3.5 -rotate-90 text-ink-subtle"
                        />
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
