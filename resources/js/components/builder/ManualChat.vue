<script setup lang="ts">
/**
 * The "Agregar gráfica" panel, redesigned as an embedded BI analyst. On open it
 * asks the server for the analyses worth adding to THIS board — each ranked and
 * grounded in a real computed fact, with a live mini-preview — plus the coverage
 * gaps. The free-text ask (deterministic mini-Express) stays at the bottom for
 * when the user knows exactly what they want.
 */
import RecommendationPreview from '@/components/builder/RecommendationPreview.vue';
import { BarChart3, Check, Send, Sparkles, TriangleAlert } from '@lucide/vue';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{ appId: string; pageSlug?: string }>();
const emit = defineEmits<{
    (e: 'added', blockId: string): void;
    /** Route a ready-made ask into the main builder chat composer. */
    (e: 'prefill', prompt: string): void;
}>();

interface Recommendation {
    id: string;
    kicker: string;
    title: string;
    why: string;
    form: string;
    flag: { tone: 'hot' | 'gap'; text: string } | null;
    preview: {
        kind: 'pareto' | 'area' | 'gauge' | 'bars' | 'scatter' | 'combo';
        values?: number[];
        value?: number;
        target?: number;
        points?: number[][];
        line?: number[];
    };
}

const FORM_LABEL_KEYS: Record<string, string> = {
    pareto: 'apps.builder.analyst.chart_type.pareto',
    area: 'apps.builder.analyst.chart_type.area',
    line: 'apps.builder.analyst.chart_type.line',
    bar: 'apps.builder.analyst.chart_type.bar',
    hbar: 'apps.builder.analyst.chart_type.hbar',
    donut: 'apps.builder.analyst.chart_type.donut',
    gauge: 'apps.builder.analyst.chart_type.gauge',
    treemap: 'apps.builder.analyst.chart_type.treemap',
    scatter: 'apps.builder.analyst.chart_type.scatter',
    combo: 'apps.builder.analyst.chart_type.combo',
    stat: 'apps.builder.analyst.chart_type.stat',
    pivot: 'apps.builder.analyst.chart_type.pivot',
    insight: 'apps.builder.analyst.chart_type.insight',
};

function formLabel(form: string): string {
    const key = FORM_LABEL_KEYS[form];
    return key ? t(key) : form;
}

interface SourceMapping {
    field: string;
    type: string;
    external: string;
}
interface SourceImplementation {
    kind: 'connected' | 'internal';
    integration_id?: string;
    integration_name?: string;
    tool?: string;
    method?: string;
    path?: string;
    arguments?: Record<string, unknown>;
    collection_path?: string;
    id_path?: string;
    fields?: SourceMapping[];
}
interface SourceDetail {
    name: string;
    slug?: string;
    description?: string | null;
    rows: number;
    measures: string[];
    dimensions: string[];
    implementation?: SourceImplementation;
}

const loading = ref(true);
const recs = ref<Recommendation[]>([]);
const gaps = ref<{ text: string }[]>([]);
const dataQuality = ref<{ level: 'warn' | 'info'; text: string }[]>([]);
const domain = ref<{ label: string } | null>(null);
const sources = ref(0);
const sourcesDetail = ref<SourceDetail[]>([]);
// The source whose "how it's built" modal is open (null = closed).
const selectedSource = ref<SourceDetail | null>(null);
const selectedArgs = computed<[string, string][]>(() =>
    Object.entries(selectedSource.value?.implementation?.arguments ?? {}).map(
        ([k, v]) => [k, typeof v === 'object' ? JSON.stringify(v) : String(v)],
    ),
);
const sourceSuggestions = ref<{ title: string; why: string }[]>([]);
const showSources = ref(false);
const addingId = ref<string | null>(null);
const done = ref<Set<string>>(new Set());

// Free-text ask (kept)
const input = ref('');
const asking = ref(false);
const askReply = ref<string | null>(null);

async function loadRecommendations() {
    loading.value = true;
    try {
        const { data } = await axios.get(
            `/apps/${props.appId}/builder/recommendations`,
            { params: { page: props.pageSlug ?? undefined } },
        );
        recs.value = data.recommendations ?? [];
        gaps.value = data.gaps ?? [];
        dataQuality.value = data.data_quality ?? [];
        domain.value = data.domain ?? null;
        sources.value = data.sources ?? 0;
        sourcesDetail.value = data.sources_detail ?? [];
        sourceSuggestions.value = data.source_suggestions ?? [];
    } catch {
        recs.value = [];
    } finally {
        loading.value = false;
    }
}
onMounted(loadRecommendations);

async function add(rec: Recommendation) {
    if (addingId.value) return;
    addingId.value = rec.id;
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/charts/from-recommendation`,
            { recommendation_id: rec.id, page_slug: props.pageSlug ?? null },
        );
        done.value.add(rec.id);
        if (data.block_id) emit('added', data.block_id);
        // Re-read: the cut we just added drops out (now on the board) and the
        // analyst surfaces the next-best analysis — a follow-up without asking.
        loadRecommendations();
    } catch (e: unknown) {
        askReply.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? t('apps.builder.analyst.add_failed');
    } finally {
        addingId.value = null;
    }
}

const visibleRecs = computed(() =>
    recs.value.filter((r) => !done.value.has(r.id)),
);

// An "enrich" suggestion names a KIND of data the org hasn't connected yet — it
// has no board action of its own. Clicking it hands the main builder chat a
// ready-made connect request (the flow the hint points to) so the user acts in
// one tap instead of retyping the idea.
function connectSource(sug: { title: string; why: string }) {
    emit(
        'prefill',
        t('apps.builder.analyst.connect_request', {
            title: sug.title,
            why: sug.why,
        }),
    );
}

async function send() {
    const prompt = input.value.trim();
    if (prompt === '' || asking.value) return;
    input.value = '';
    asking.value = true;
    askReply.value = null;
    try {
        const { data } = await axios.post(
            `/apps/${props.appId}/builder/charts`,
            { prompt, page_slug: props.pageSlug ?? null },
        );
        askReply.value = data.message;
        if (data.block_id) {
            emit('added', data.block_id);
            loadRecommendations(); // the new chart changes what's worth adding next
        }
    } catch (e: unknown) {
        askReply.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? t('apps.builder.analyst.derive_failed');
    } finally {
        asking.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <!-- header -->
        <header class="border-b border-soft px-4 pt-3.5 pb-3">
            <div class="flex items-center gap-2.5">
                <span
                    class="flex size-7 items-center justify-center rounded-[9px] text-white"
                    style="
                        background: linear-gradient(140deg, #2f6cff, #00ce7c);
                    "
                >
                    <Sparkles class="size-4" />
                </span>
                <span class="text-sm font-semibold text-ink">{{
                    t('apps.builder.analyst.title')
                }}</span>
                <span
                    v-if="loading"
                    class="ml-auto text-[11px] font-medium text-ink-subtle"
                    >{{ t('apps.builder.analyst.reading_data') }}</span
                >
                <span
                    v-else
                    class="ml-auto flex items-center gap-1.5 text-[11px] font-semibold text-sp-success"
                >
                    <span class="size-1.5 rounded-full bg-sp-success" />
                    {{ t('apps.builder.activity_ready') }}
                </span>
            </div>
            <p
                v-if="domain && !loading"
                class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-ink-subtle"
            >
                <span
                    class="inline-flex items-center gap-1.5 rounded-pill border px-2 py-0.5 font-semibold"
                    style="
                        color: #00b06a;
                        border-color: rgba(0, 176, 106, 0.28);
                        background: rgba(0, 176, 106, 0.1);
                    "
                >
                    <Check class="size-3" />
                    {{ domain.label }}
                </span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-pill px-1.5 py-0.5 text-ink-subtle underline decoration-dotted underline-offset-2 transition-colors hover:text-accent-blue"
                    @click="showSources = true"
                >
                    {{
                        t('apps.builder.analyst.sources_read', {
                            count: sources,
                        })
                    }}
                    <svg
                        viewBox="0 0 24 24"
                        class="size-3"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M9 18l6-6-6-6" />
                    </svg>
                </button>
            </p>
        </header>

        <div class="min-h-0 flex-1 space-y-1 overflow-y-auto pb-3">
            <!-- FUENTES: what each source provides + what to add next -->
            <template v-if="showSources">
                <button
                    type="button"
                    class="mx-3 mt-3 inline-flex items-center gap-1.5 text-[12px] font-semibold text-ink-muted transition-colors hover:text-accent-blue"
                    @click="showSources = false"
                >
                    <svg
                        viewBox="0 0 24 24"
                        class="size-3.5"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M15 18l-6-6 6-6" />
                    </svg>
                    {{ t('apps.builder.analyst.recommendations') }}
                </button>

                <div
                    class="px-4 pt-3 pb-1 text-[10.5px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                >
                    {{ t('apps.builder.analyst.sources_read_heading') }}
                    <span class="ml-1 text-ink-subtle normal-case">
                        · {{ t('apps.builder.analyst.sources_subtitle') }}
                    </span>
                </div>
                <div class="space-y-2 px-3">
                    <div
                        v-for="(s, i) in sourcesDetail"
                        :key="i"
                        role="button"
                        tabindex="0"
                        :title="t('apps.builder.analyst.view_implementation')"
                        class="cursor-pointer rounded-xl border border-soft bg-surface px-3.5 py-3 transition-colors hover:border-strong focus:border-strong focus:outline-none"
                        @click="selectedSource = s"
                        @keydown.enter.prevent="selectedSource = s"
                        @keydown.space.prevent="selectedSource = s"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-[13px] font-semibold text-ink">{{
                                s.name
                            }}</span>
                            <span
                                class="shrink-0 text-[10.5px] text-ink-subtle"
                                >{{
                                    t('apps.builder.analyst.rows', {
                                        count: s.rows.toLocaleString(),
                                    })
                                }}</span
                            >
                        </div>
                        <p
                            v-if="s.measures.length"
                            class="mt-1.5 text-[11.5px] leading-relaxed text-ink-muted"
                        >
                            <span class="text-ink-subtle">{{
                                t('apps.builder.analyst.measures_label')
                            }}</span>
                            {{ s.measures.join(', ') }}
                        </p>
                        <p
                            v-if="s.dimensions.length"
                            class="mt-0.5 text-[11.5px] leading-relaxed text-ink-muted"
                        >
                            <span class="text-ink-subtle">{{
                                t('apps.builder.analyst.dimensions_label')
                            }}</span>
                            {{ s.dimensions.join(', ') }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="sourceSuggestions.length"
                    class="px-4 pt-4 pb-1 text-[10.5px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                >
                    {{ t('apps.builder.analyst.enrich') }}
                </div>
                <div
                    v-if="sourceSuggestions.length"
                    class="space-y-2 px-3 pb-2"
                >
                    <div
                        v-for="(sug, i) in sourceSuggestions"
                        :key="i"
                        role="button"
                        tabindex="0"
                        :title="t('apps.builder.analyst.connect_source_action')"
                        class="flex cursor-pointer gap-2.5 rounded-xl border border-dashed border-medium px-3.5 py-2.5 transition-colors hover:border-accent-blue focus:border-accent-blue focus:outline-none"
                        @click="connectSource(sug)"
                        @keydown.enter.prevent="connectSource(sug)"
                        @keydown.space.prevent="connectSource(sug)"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            class="mt-0.5 size-4 shrink-0 text-accent-blue"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        <span>
                            <span
                                class="block text-[12.5px] font-semibold text-ink"
                                >{{ sug.title }}</span
                            >
                            <span
                                class="block text-[11.5px] leading-relaxed text-ink-muted"
                                >{{ sug.why }}</span
                            >
                        </span>
                    </div>
                </div>
                <p
                    class="px-4 pt-1 pb-2 text-[10.5px] leading-relaxed text-ink-subtle"
                >
                    {{ t('apps.builder.analyst.connect_source_hint') }}
                </p>
            </template>

            <template v-else>
                <!-- Data-confidence flags: read the material before trusting it -->
                <div
                    v-if="!loading && dataQuality.length"
                    class="flex flex-wrap gap-2 px-4 pt-3"
                >
                    <span
                        v-for="(q, i) in dataQuality"
                        :key="i"
                        class="inline-flex items-center gap-1.5 rounded-pill border px-2.5 py-1 text-[11px]"
                        :class="
                            q.level === 'warn'
                                ? 'border-sp-warning/30 text-sp-warning'
                                : 'border-soft bg-surface text-ink-muted'
                        "
                        :style="
                            q.level === 'warn'
                                ? { background: 'rgba(224,145,42,0.1)' }
                                : undefined
                        "
                    >
                        <svg
                            viewBox="0 0 24 24"
                            class="size-3"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <template v-if="q.level === 'warn'">
                                <path d="M12 9v4M12 17h.01" />
                                <path
                                    d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6A2 2 0 0 0 22 18L13.7 3.9a2 2 0 0 0-3.4 0z"
                                />
                            </template>
                            <template v-else>
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 16v-4M12 8h.01" />
                            </template>
                        </svg>
                        {{ q.text }}
                    </span>
                </div>

                <div
                    class="px-4 pt-3.5 pb-1 text-[10.5px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                >
                    {{ t('apps.builder.analyst.recommended_analyses') }}
                    <span
                        v-if="!loading && visibleRecs.length"
                        class="ml-1 rounded-pill bg-accent-blue/12 px-1.5 py-px text-[10px] text-accent-blue"
                        >{{ visibleRecs.length }}</span
                    >
                </div>

                <!-- loading: message + skeletons -->
                <div v-if="loading" class="space-y-2.5 px-3">
                    <div
                        class="flex items-center gap-2.5 rounded-xl border border-soft bg-surface px-3.5 py-3"
                    >
                        <span
                            class="size-4 shrink-0 animate-spin rounded-full border-2 border-accent-blue/30 border-t-accent-blue"
                        />
                        <span class="text-[12.5px] font-medium text-ink-muted">
                            {{
                                t(
                                    'apps.builder.analyst.loading_recommendations',
                                )
                            }}
                        </span>
                    </div>
                    <div
                        v-for="i in 2"
                        :key="i"
                        class="h-40 animate-pulse rounded-xl border border-soft bg-surface"
                    />
                </div>

                <!-- empty -->
                <p
                    v-else-if="visibleRecs.length === 0"
                    class="px-5 py-6 text-center text-xs text-ink-subtle"
                >
                    {{ t('apps.builder.analyst.empty') }}
                </p>

                <!-- recommendation cards -->
                <div v-else class="space-y-2.5 px-3">
                    <article
                        v-for="rec in visibleRecs"
                        :key="rec.id"
                        class="overflow-hidden rounded-xl border border-soft bg-surface transition-colors hover:border-medium"
                    >
                        <div
                            class="relative h-28 border-b border-soft"
                            style="
                                background: linear-gradient(
                                    180deg,
                                    rgba(59, 130, 246, 0.05),
                                    transparent 70%
                                );
                            "
                        >
                            <span
                                v-if="rec.flag"
                                class="absolute top-2 right-2 z-10 inline-flex items-center gap-1.5 rounded-pill px-2 py-0.5 text-[10px] font-bold"
                                :class="
                                    rec.flag.tone === 'hot'
                                        ? 'text-sp-warning'
                                        : 'text-accent-blue'
                                "
                                :style="{
                                    background:
                                        rec.flag.tone === 'hot'
                                            ? 'rgba(224,145,42,0.15)'
                                            : 'rgba(59,130,246,0.14)',
                                }"
                            >
                                <span
                                    class="size-1 rounded-full bg-current"
                                />{{ rec.flag.text }}
                            </span>
                            <RecommendationPreview :preview="rec.preview" />
                        </div>
                        <div class="px-3.5 pt-2.5">
                            <div
                                class="text-[9.5px] font-bold tracking-[0.11em] text-ink-subtle uppercase"
                            >
                                {{ rec.kicker }}
                            </div>
                            <h3 class="mt-1 text-[14px] font-semibold text-ink">
                                {{ rec.title }}
                            </h3>
                            <p
                                class="mt-1 text-[12px] leading-relaxed text-ink-muted"
                            >
                                {{ rec.why }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 px-3.5 pt-2.5 pb-3">
                            <span
                                class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-ink-subtle"
                            >
                                <BarChart3 class="size-3.5" />
                                {{ formLabel(rec.form) }}
                            </span>
                            <button
                                type="button"
                                :disabled="addingId !== null"
                                class="ml-auto inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-[12px] font-semibold text-white transition-[filter,opacity] hover:brightness-110 disabled:opacity-50"
                                @click="add(rec)"
                            >
                                <span
                                    v-if="addingId === rec.id"
                                    class="size-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                />
                                <template v-else>
                                    <svg
                                        viewBox="0 0 24 24"
                                        class="size-3.5"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.6"
                                        stroke-linecap="round"
                                    >
                                        <path d="M12 5v14M5 12h14" />
                                    </svg>
                                </template>
                                {{ t('apps.builder.analyst.add') }}
                            </button>
                        </div>
                    </article>
                </div>

                <!-- gaps -->
                <template v-if="!loading && gaps.length">
                    <div
                        class="px-4 pt-4 pb-1 text-[10.5px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                    >
                        {{ t('apps.builder.analyst.whats_missing') }}
                    </div>
                    <div class="flex flex-wrap gap-2 px-4">
                        <span
                            v-for="(g, i) in gaps"
                            :key="i"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-soft bg-surface px-2.5 py-1 text-[11px] text-ink-muted"
                        >
                            <TriangleAlert class="size-3 text-sp-warning" />
                            {{ g.text }}
                        </span>
                    </div>
                </template>
            </template>
        </div>

        <!-- ask box -->
        <form
            class="border-t border-soft px-3.5 pt-3 pb-3.5"
            @submit.prevent="send"
        >
            <p
                v-if="askReply"
                class="mb-2 rounded-sp-sm bg-surface px-3 py-2 text-[11.5px] leading-relaxed text-ink-muted"
            >
                {{ askReply }}
            </p>
            <p class="mb-2 px-1 text-[11px] text-ink-subtle">
                {{ t('apps.builder.analyst.ask_hint') }}
            </p>
            <div
                class="flex flex-col gap-2 rounded-sp-md border border-medium bg-navy-elevated p-2.5 focus-within:border-accent-blue"
            >
                <textarea
                    v-model="input"
                    :disabled="asking"
                    rows="3"
                    :placeholder="t('apps.builder.analyst.ask_placeholder')"
                    class="min-h-[64px] w-full resize-none bg-transparent px-1 text-sm leading-relaxed text-ink outline-none placeholder:text-ink-subtle"
                    @keydown.enter.exact.prevent="send"
                />
                <button
                    type="submit"
                    :disabled="asking || input.trim() === ''"
                    class="flex size-8 shrink-0 items-center justify-center self-end rounded-full bg-accent-blue text-white transition-opacity disabled:opacity-40"
                >
                    <Send class="size-4" />
                </button>
            </div>
        </form>

        <!-- "How this source is built" modal: opened by clicking a source card -->
        <Teleport to="body">
            <div
                v-if="selectedSource"
                class="fixed inset-0 z-[70] flex items-center justify-center p-4"
            >
                <div
                    class="absolute inset-0 bg-black/50"
                    @click="selectedSource = null"
                />
                <div
                    class="relative z-10 max-h-[85vh] w-full max-w-md overflow-y-auto rounded-2xl border border-soft bg-navy-elevated p-5 shadow-xl"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3
                                class="truncate text-[15px] font-semibold text-ink"
                            >
                                {{ selectedSource.name }}
                            </h3>
                            <p
                                v-if="selectedSource.description"
                                class="mt-1 text-[12px] leading-relaxed text-ink-muted"
                            >
                                {{ selectedSource.description }}
                            </p>
                        </div>
                        <button
                            type="button"
                            :aria-label="t('apps.builder.analyst.close')"
                            class="shrink-0 rounded-full p-1 text-ink-subtle transition-colors hover:text-ink"
                            @click="selectedSource = null"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                class="size-4"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.4"
                                stroke-linecap="round"
                            >
                                <path d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>

                    <p
                        class="mt-4 text-[10px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                    >
                        {{ t('apps.builder.analyst.how_built') }}
                    </p>
                    <dl class="mt-2 space-y-1.5 text-[12px]">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-ink-subtle">
                                {{ t('apps.builder.analyst.source_type') }}
                            </dt>
                            <dd class="text-right text-ink">
                                {{
                                    selectedSource.implementation?.kind ===
                                    'connected'
                                        ? t(
                                              'apps.builder.analyst.type_connected',
                                          )
                                        : t(
                                              'apps.builder.analyst.type_internal',
                                          )
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="
                                selectedSource.implementation?.integration_name
                            "
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-ink-subtle">
                                {{ t('apps.builder.analyst.connection') }}
                            </dt>
                            <dd class="text-right text-ink">
                                {{
                                    selectedSource.implementation
                                        .integration_name
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="selectedSource.implementation?.tool"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-ink-subtle">
                                {{ t('apps.builder.analyst.tool') }}
                            </dt>
                            <dd class="text-right">
                                <code
                                    class="rounded border border-soft px-1 py-0.5 font-mono text-[11px] text-ink"
                                    >{{
                                        selectedSource.implementation.tool
                                    }}</code
                                >
                            </dd>
                        </div>
                        <div
                            v-if="selectedSource.implementation?.path"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-ink-subtle">
                                {{ t('apps.builder.analyst.endpoint') }}
                            </dt>
                            <dd class="text-right">
                                <code
                                    class="rounded border border-soft px-1 py-0.5 font-mono text-[11px] text-ink"
                                    >{{ selectedSource.implementation.method }}
                                    {{
                                        selectedSource.implementation.path
                                    }}</code
                                >
                            </dd>
                        </div>
                        <div
                            v-if="
                                selectedSource.implementation?.collection_path
                            "
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-ink-subtle">
                                {{ t('apps.builder.analyst.collection_path') }}
                            </dt>
                            <dd class="text-right">
                                <code
                                    class="font-mono text-[11px] text-ink-muted"
                                    >{{
                                        selectedSource.implementation
                                            .collection_path
                                    }}</code
                                >
                            </dd>
                        </div>
                        <div
                            v-if="selectedSource.implementation?.id_path"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt class="text-ink-subtle">
                                {{ t('apps.builder.analyst.id_path') }}
                            </dt>
                            <dd class="text-right">
                                <code
                                    class="font-mono text-[11px] text-ink-muted"
                                    >{{
                                        selectedSource.implementation.id_path
                                    }}</code
                                >
                            </dd>
                        </div>
                    </dl>

                    <template v-if="selectedArgs.length">
                        <p
                            class="mt-4 text-[10px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                        >
                            {{ t('apps.builder.analyst.arguments') }}
                        </p>
                        <div class="mt-1.5 space-y-1">
                            <div
                                v-for="[k, v] in selectedArgs"
                                :key="k"
                                class="flex items-baseline justify-between gap-3 text-[12px]"
                            >
                                <code
                                    class="font-mono text-[11px] text-ink-subtle"
                                    >{{ k }}</code
                                >
                                <code
                                    class="text-right font-mono text-[11px] text-ink"
                                    >{{ v }}</code
                                >
                            </div>
                        </div>
                    </template>

                    <template
                        v-if="selectedSource.implementation?.fields?.length"
                    >
                        <p
                            class="mt-4 text-[10px] font-bold tracking-[0.12em] text-ink-subtle uppercase"
                        >
                            {{ t('apps.builder.analyst.field_mapping') }}
                        </p>
                        <table class="mt-1.5 w-full text-[12px]">
                            <thead>
                                <tr class="text-left text-ink-subtle">
                                    <th class="pb-1 font-normal">
                                        {{
                                            t(
                                                'apps.builder.analyst.mapping_field',
                                            )
                                        }}
                                    </th>
                                    <th class="pb-1 text-right font-normal">
                                        {{
                                            t(
                                                'apps.builder.analyst.mapping_source',
                                            )
                                        }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(m, mi) in selectedSource
                                        .implementation.fields"
                                    :key="mi"
                                    class="border-t border-soft"
                                >
                                    <td class="py-1 text-ink">
                                        {{ m.field
                                        }}<span
                                            v-if="m.type"
                                            class="ml-1.5 text-[10px] text-ink-subtle"
                                            >{{ m.type }}</span
                                        >
                                    </td>
                                    <td class="py-1 text-right">
                                        <code
                                            class="font-mono text-[11px] text-ink-muted"
                                            >{{ m.external }}</code
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </div>
            </div>
        </Teleport>
    </div>
</template>
