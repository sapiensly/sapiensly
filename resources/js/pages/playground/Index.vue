<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { normalizeChatMarkdown } from '@/lib/markdown';
import { Head } from '@inertiajs/vue3';
import {
    Braces,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Copy,
    Download,
    Gauge,
    History,
    Loader2,
    Play,
    RefreshCw,
    Timer,
} from '@lucide/vue';
import axios from 'axios';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import RunMetrics from './RunMetrics.vue';
import type { RunMetrics as RunMetricsData } from './metrics';

interface CapabilityModel {
    id: string;
    driver: string;
    name: string;
    label: string;
}

interface Capability {
    key: string;
    pickerCapability: string;
    input: 'prompt' | 'text' | 'image_q' | 'pdf' | 'image' | 'audio' | 'rerank';
    output: 'text' | 'embeddings' | 'image' | 'audio' | 'rerank';
    defaultModel: string | null;
}

interface Usage {
    prompt_tokens?: number;
    completion_tokens?: number;
    total_tokens?: number;
    cost?: number;
    estimated?: boolean;
}

interface RunResult {
    ok: boolean;
    run_id?: string | null;
    status?: 'queued' | 'running' | 'ok' | 'error';
    model?: string;
    served_by?: string;
    text?: string;
    image?: string;
    audio?: string;
    dimensions?: number;
    preview?: number[];
    ranked?: { index: number; score: number; document: string }[];
    usage?: Usage;
    duration_ms?: number;
    metrics?: RunMetricsData | null;
    error?: string;
}

interface HistoryItem {
    id: string;
    capability: string;
    driver: string | null;
    model: string | null;
    served_by: string | null;
    status: 'queued' | 'running' | 'ok' | 'error';
    excerpt: string | null;
    total_tokens: number | null;
    cost: number | null;
    duration_ms: number | null;
    tokens_per_second: number | null;
    error: string | null;
    user: string | null;
    created_at: string | null;
}

interface HistoryDetail extends HistoryItem {
    input: Record<string, unknown> | null;
    file_meta: { name?: string; mime?: string; size?: number } | null;
    output_text: string | null;
    output: Record<string, unknown> | null;
    response: Record<string, unknown> | null;
    raw: Record<string, unknown> | null;
    usage: Usage | null;
    queue_wait_ms: number | null;
    metrics: RunMetricsData | null;
}

const props = defineProps<{
    capabilities: Capability[];
    modelsByCapability: Record<string, CapabilityModel[]>;
}>();

const { t } = useI18n();

const selectedKey = ref(props.capabilities[0]?.key ?? 'text');
const selected = computed(
    () =>
        props.capabilities.find((c) => c.key === selectedKey.value) ??
        props.capabilities[0],
);
const models = computed<CapabilityModel[]>(
    () => props.modelsByCapability[selected.value?.pickerCapability] ?? [],
);

// Per-capability form + run state, keyed so switching tabs keeps inputs.
type FormState = {
    modelId: string;
    prompt: string;
    text: string;
    query: string;
    documents: string;
    question: string;
    voice: string;
    gender: string;
    instructions: string;
    file: File | null;
    running: boolean;
    result: RunResult | null;
    elapsedMs: number;
};
const forms = reactive<Record<string, FormState>>({});
function form(key: string): FormState {
    if (!forms[key]) {
        forms[key] = {
            modelId: '',
            prompt: '',
            text: '',
            query: '',
            documents: '',
            question: '',
            voice: '',
            gender: '',
            instructions: '',
            file: null,
            running: false,
            result: null,
            elapsedMs: 0,
        };
    }
    return forms[key];
}
const current = computed(() => form(selectedKey.value));

const hasModel = computed(
    () => !!selected.value?.defaultModel || current.value.modelId !== '',
);

const fileAccept = computed(() => {
    switch (selected.value?.input) {
        case 'pdf':
            return 'application/pdf';
        case 'image':
        case 'image_q':
            return 'image/*';
        case 'audio':
            return 'audio/*';
        default:
            return '';
    }
});

function onFile(e: Event) {
    const input = e.target as HTMLInputElement;
    current.value.file = input.files?.[0] ?? null;
}

const copied = ref(false);
async function copyText(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        /* clipboard unavailable */
    }
}

const copiedRunId = ref<string | null>(null);
async function copyRunId(id: string) {
    try {
        await navigator.clipboard.writeText(id);
        copiedRunId.value = id;
        setTimeout(() => (copiedRunId.value = null), 1500);
    } catch {
        /* clipboard unavailable */
    }
}

function triggerDownload(href: string, filename: string) {
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
}

function downloadText(text: string, filename: string) {
    const url = URL.createObjectURL(
        new Blob([text], { type: 'text/markdown;charset=utf-8' }),
    );
    triggerDownload(url, filename);
    URL.revokeObjectURL(url);
}

function renderMarkdown(content: string | null | undefined): string {
    if (!content) return '';
    const raw = marked.parse(normalizeChatMarkdown(content), {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}

function fmtDuration(ms: number): string {
    return ms < 1000 ? `${Math.round(ms)} ms` : `${(ms / 1000).toFixed(1)} s`;
}

function fmtCost(cost: number): string {
    // Sub-cent costs need more precision than 2 decimals.
    return cost >= 0.01 ? `$${cost.toFixed(2)}` : `$${cost.toFixed(5)}`;
}

// ── Run history ──────────────────────────────────────────────────────
const history = reactive({
    items: [] as HistoryItem[],
    page: 1,
    lastPage: 1,
    total: 0,
    loading: false,
    onlyCurrent: true,
});
const expandedId = ref<string | null>(null);
const details = reactive<Record<string, HistoryDetail | undefined>>({});
const detailLoading = ref<string | null>(null);
// Raw JSON payloads are opt-in per run — metrics lead, JSON is on demand.
const rawJsonShown = reactive<Record<string, boolean>>({});

async function loadHistory(page = 1) {
    history.loading = true;
    try {
        const params: Record<string, string | number> = { page };
        if (history.onlyCurrent) params.capability = selectedKey.value;
        const { data } = await axios.get('/playground/history', { params });
        history.items = data.data;
        history.page = data.current_page;
        history.lastPage = data.last_page;
        history.total = data.total;
    } catch {
        /* history is best-effort; the runner keeps working without it */
    } finally {
        history.loading = false;
    }
}

async function toggleDetail(id: string) {
    if (expandedId.value === id) {
        expandedId.value = null;
        return;
    }
    expandedId.value = id;
    if (!details[id]) {
        detailLoading.value = id;
        try {
            const { data } = await axios.get(`/playground/history/${id}`);
            details[id] = data;
        } catch {
            expandedId.value = null;
        } finally {
            detailLoading.value = null;
        }
    }
}

function toggleHistoryScope(onlyCurrent: boolean) {
    if (history.onlyCurrent === onlyCurrent) return;
    history.onlyCurrent = onlyCurrent;
    loadHistory(1);
}

function fmtJson(value: unknown): string {
    return JSON.stringify(value, null, 2);
}

function fmtTimestamp(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}

onMounted(() => loadHistory(1));
watch(selectedKey, () => {
    if (history.onlyCurrent) loadHistory(1);
});

const MAX_UPLOAD_MB = 30;

// Runs execute on the queue; the browser polls until the job lands a
// terminal state. Generous ceiling — providers themselves time out first.
const POLL_INTERVAL_MS = 1500;
const POLL_TIMEOUT_MS = 10 * 60 * 1000;

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function pollRun(runId: string): Promise<RunResult> {
    const startedAt = Date.now();
    for (;;) {
        await sleep(POLL_INTERVAL_MS);
        const { data } = await axios.get<RunResult>(`/playground/run/${runId}`);
        if (data.status === 'ok') return data;
        if (data.status === 'error')
            return {
                ok: false,
                run_id: runId,
                error: data.error,
                duration_ms: data.duration_ms,
            };
        if (Date.now() - startedAt > POLL_TIMEOUT_MS)
            return { ok: false, error: t('app_v2.playground.poll_timeout') };
    }
}

async function run() {
    const cap = selected.value;
    const f = current.value;
    if (!cap || f.running) return;

    if (f.file && f.file.size > MAX_UPLOAD_MB * 1024 * 1024) {
        f.result = {
            ok: false,
            error: t('app_v2.playground.file_too_large', { mb: MAX_UPLOAD_MB }),
        };
        return;
    }

    f.running = true;
    f.result = null;

    // Live stopwatch from RUN until the response settles.
    const startedAt = performance.now();
    f.elapsedMs = 0;
    const timer = window.setInterval(() => {
        f.elapsedMs = performance.now() - startedAt;
    }, 100);

    const fd = new FormData();
    fd.append('capability', cap.key);
    if (f.modelId) fd.append('model_id', f.modelId);

    if (cap.input === 'prompt' || cap.input === 'image_q')
        fd.append('prompt', cap.input === 'image_q' ? f.question : f.prompt);
    if (cap.input === 'text') fd.append('text', f.text);
    if (cap.key === 'speech_generation') {
        if (f.voice) fd.append('voice', f.voice);
        if (f.gender) fd.append('gender', f.gender);
        if (f.instructions) fd.append('instructions', f.instructions);
    }
    if (cap.input === 'rerank') {
        fd.append('query', f.query);
        f.documents
            .split('\n')
            .map((d) => d.trim())
            .filter(Boolean)
            .forEach((d) => fd.append('documents[]', d));
    }
    if (['pdf', 'image', 'image_q', 'audio'].includes(cap.input) && f.file)
        fd.append('file', f.file);

    try {
        const { data } = await axios.post<RunResult>('/playground/run', fd);
        // Queued (async worker) → poll until terminal; sync driver returns
        // the finished payload directly.
        if (data.status === 'queued' || data.status === 'running') {
            // Surface the in-flight row in the history right away.
            loadHistory(1);
            f.result = await pollRun(data.run_id!);
        } else {
            f.result = data;
        }
    } catch (err) {
        const e = err as { response?: { data?: RunResult } };
        f.result = e.response?.data ?? {
            ok: false,
            error: t('app_v2.playground.error'),
        };
    } finally {
        window.clearInterval(timer);
        f.elapsedMs = performance.now() - startedAt;
        f.running = false;
        // Every run (success or error) lands in the persisted history.
        loadHistory(1);
    }
}
</script>

<template>
    <Head :title="t('app_v2.nav.playground')" />

    <AppLayoutV2 :title="t('app_v2.nav.playground')">
        <div class="space-y-6">
            <PageHeader
                :title="t('app_v2.playground.heading')"
                :description="t('app_v2.playground.description')"
            />

            <div class="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
                <!-- Capability list -->
                <nav class="flex flex-col gap-1">
                    <button
                        v-for="c in capabilities"
                        :key="c.key"
                        type="button"
                        class="rounded-lg px-3 py-2 text-left text-sm transition-colors"
                        :class="
                            c.key === selectedKey
                                ? 'bg-accent-blue/12 font-medium text-accent-blue'
                                : 'text-ink-muted hover:bg-surface hover:text-ink'
                        "
                        @click="selectedKey = c.key"
                    >
                        {{ t(`app_v2.playground.cap.${c.key}`) }}
                    </button>
                </nav>

                <!-- Runner -->
                <div
                    v-if="selected"
                    class="space-y-4 rounded-2xl border border-soft bg-navy p-5"
                >
                    <div>
                        <h2 class="text-base font-semibold text-ink">
                            {{ t(`app_v2.playground.cap.${selected.key}`) }}
                        </h2>
                        <p class="text-xs text-ink-muted">
                            {{ t(`app_v2.playground.capdesc.${selected.key}`) }}
                        </p>
                    </div>

                    <!-- Model picker -->
                    <div class="space-y-1.5">
                        <label class="text-xs text-ink-muted">{{
                            t('app_v2.playground.model_label')
                        }}</label>
                        <select
                            v-model="current.modelId"
                            class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                        >
                            <option value="">
                                {{
                                    selected.defaultModel
                                        ? `${t('app_v2.playground.model_use_default')} (${selected.defaultModel})`
                                        : t(
                                              'app_v2.playground.model_use_default',
                                          )
                                }}
                            </option>
                            <option
                                v-for="m in models"
                                :key="m.id"
                                :value="m.id"
                            >
                                {{ m.driver }} · {{ m.name }}
                            </option>
                        </select>
                        <p v-if="!hasModel" class="text-[11px] text-sp-danger">
                            {{ t('app_v2.playground.not_configured') }}
                            <a
                                href="/admin/ai"
                                class="underline hover:text-ink"
                                >{{ t('app_v2.playground.configure') }}</a
                            >
                        </p>
                    </div>

                    <!-- Inputs by kind -->
                    <div v-if="selected.input === 'prompt'" class="space-y-1.5">
                        <label class="text-xs text-ink-muted">{{
                            t('app_v2.playground.field_prompt')
                        }}</label>
                        <textarea
                            v-model="current.prompt"
                            rows="4"
                            class="w-full rounded-md border border-medium bg-surface p-2.5 text-sm text-ink"
                        />
                    </div>

                    <div v-if="selected.input === 'text'" class="space-y-1.5">
                        <label class="text-xs text-ink-muted">{{
                            t('app_v2.playground.field_text')
                        }}</label>
                        <textarea
                            v-model="current.text"
                            rows="4"
                            class="w-full rounded-md border border-medium bg-surface p-2.5 text-sm text-ink"
                        />
                    </div>

                    <!-- Voice controls for speech generation. -->
                    <div
                        v-if="selected.key === 'speech_generation'"
                        class="grid grid-cols-2 gap-3"
                    >
                        <div class="space-y-1.5">
                            <label class="text-xs text-ink-muted">{{
                                t('app_v2.playground.field_voice')
                            }}</label>
                            <input
                                v-model="current.voice"
                                :placeholder="
                                    t('app_v2.playground.voice_placeholder')
                                "
                                class="h-9 w-full rounded-md border border-medium bg-surface px-2.5 text-sm text-ink"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-xs text-ink-muted">{{
                                t('app_v2.playground.field_gender')
                            }}</label>
                            <select
                                v-model="current.gender"
                                class="h-9 w-full rounded-md border border-medium bg-surface px-2 text-sm text-ink"
                            >
                                <option value="">
                                    {{ t('app_v2.playground.gender_auto') }}
                                </option>
                                <option value="female">
                                    {{ t('app_v2.playground.gender_female') }}
                                </option>
                                <option value="male">
                                    {{ t('app_v2.playground.gender_male') }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2 space-y-1.5">
                            <label class="text-xs text-ink-muted">{{
                                t('app_v2.playground.field_instructions')
                            }}</label>
                            <input
                                v-model="current.instructions"
                                :placeholder="
                                    t(
                                        'app_v2.playground.instructions_placeholder',
                                    )
                                "
                                class="h-9 w-full rounded-md border border-medium bg-surface px-2.5 text-sm text-ink"
                            />
                        </div>
                    </div>

                    <div v-if="selected.input === 'rerank'" class="space-y-3">
                        <div class="space-y-1.5">
                            <label class="text-xs text-ink-muted">{{
                                t('app_v2.playground.field_query')
                            }}</label>
                            <input
                                v-model="current.query"
                                class="h-9 w-full rounded-md border border-medium bg-surface px-2.5 text-sm text-ink"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-xs text-ink-muted">{{
                                t('app_v2.playground.field_documents')
                            }}</label>
                            <textarea
                                v-model="current.documents"
                                rows="5"
                                class="w-full rounded-md border border-medium bg-surface p-2.5 text-sm text-ink"
                            />
                        </div>
                    </div>

                    <div
                        v-if="
                            ['pdf', 'image', 'image_q', 'audio'].includes(
                                selected.input,
                            )
                        "
                        class="space-y-3"
                    >
                        <div class="space-y-1.5">
                            <label class="text-xs text-ink-muted">{{
                                t('app_v2.playground.field_file')
                            }}</label>
                            <input
                                type="file"
                                :accept="fileAccept"
                                class="block w-full text-sm text-ink-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-sm file:text-ink"
                                @change="onFile"
                            />
                        </div>
                        <div
                            v-if="selected.input === 'image_q'"
                            class="space-y-1.5"
                        >
                            <label class="text-xs text-ink-muted">{{
                                t('app_v2.playground.field_question')
                            }}</label>
                            <input
                                v-model="current.question"
                                class="h-9 w-full rounded-md border border-medium bg-surface px-2.5 text-sm text-ink"
                            />
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            :disabled="current.running || !hasModel"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-accent-blue px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                            @click="run"
                        >
                            <Loader2
                                v-if="current.running"
                                class="size-4 animate-spin"
                            />
                            <Play v-else class="size-4" />
                            {{
                                current.running
                                    ? t('app_v2.playground.running')
                                    : t('app_v2.playground.run')
                            }}
                        </button>
                        <!-- Live stopwatch. -->
                        <span
                            v-if="current.running || current.elapsedMs > 0"
                            class="inline-flex items-center gap-1 font-mono text-sm text-ink-muted tabular-nums"
                        >
                            <Timer class="size-3.5" />
                            {{ fmtDuration(current.elapsedMs) }}
                        </span>
                    </div>

                    <!-- Result -->
                    <div v-if="current.result" class="space-y-2">
                        <div
                            v-if="!current.result.ok"
                            class="rounded-xl border border-sp-danger/30 bg-sp-danger/10 p-3 text-sm text-sp-danger"
                        >
                            {{ current.result.error }}
                        </div>
                        <template v-else>
                            <div
                                class="flex items-center justify-between gap-2 text-xs text-ink-subtle"
                            >
                                <span>{{ t('app_v2.playground.result') }}</span>
                                <div class="flex items-center gap-3">
                                    <!-- Copy / download the result. -->
                                    <button
                                        v-if="
                                            selected.output === 'text' &&
                                            current.result.text
                                        "
                                        type="button"
                                        class="inline-flex items-center gap-1 transition-colors hover:text-ink"
                                        @click="copyText(current.result.text)"
                                    >
                                        <Check
                                            v-if="copied"
                                            class="size-3.5 text-sp-success"
                                        />
                                        <Copy v-else class="size-3.5" />
                                        {{
                                            copied
                                                ? t('app_v2.playground.copied')
                                                : t('app_v2.playground.copy')
                                        }}
                                    </button>
                                    <button
                                        v-if="
                                            selected.output === 'text' &&
                                            current.result.text
                                        "
                                        type="button"
                                        class="inline-flex items-center gap-1 transition-colors hover:text-ink"
                                        @click="
                                            downloadText(
                                                current.result.text,
                                                `playground-${selected.key}.md`,
                                            )
                                        "
                                    >
                                        <Download class="size-3.5" />
                                        {{ t('app_v2.playground.download') }}
                                    </button>
                                    <button
                                        v-if="
                                            selected.output === 'image' &&
                                            current.result.image
                                        "
                                        type="button"
                                        class="inline-flex items-center gap-1 transition-colors hover:text-ink"
                                        @click="
                                            triggerDownload(
                                                current.result.image,
                                                `playground-${selected.key}.png`,
                                            )
                                        "
                                    >
                                        <Download class="size-3.5" />
                                        {{ t('app_v2.playground.download') }}
                                    </button>
                                    <button
                                        v-if="
                                            selected.output === 'audio' &&
                                            current.result.audio
                                        "
                                        type="button"
                                        class="inline-flex items-center gap-1 transition-colors hover:text-ink"
                                        @click="
                                            triggerDownload(
                                                current.result.audio,
                                                `playground-${selected.key}.mp3`,
                                            )
                                        "
                                    >
                                        <Download class="size-3.5" />
                                        {{ t('app_v2.playground.download') }}
                                    </button>
                                    <span>
                                        {{ current.result.model
                                        }}<template
                                            v-if="current.result.served_by"
                                        >
                                            · {{ current.result.served_by }}
                                        </template>
                                    </span>
                                </div>
                            </div>

                            <!-- Run performance: KPI tiles + latency split. -->
                            <RunMetrics
                                v-if="current.result.metrics"
                                :metrics="current.result.metrics"
                            />

                            <!-- Run identity. -->
                            <div
                                v-if="current.result.run_id"
                                class="text-[11px] text-ink-subtle"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 font-mono transition-colors hover:text-ink"
                                    :title="t('app_v2.playground.copy')"
                                    @click="copyRunId(current.result.run_id!)"
                                >
                                    <Check
                                        v-if="
                                            copiedRunId ===
                                            current.result.run_id
                                        "
                                        class="size-3 text-sp-success"
                                    />
                                    <Copy v-else class="size-3" />
                                    {{ current.result.run_id }}
                                </button>
                            </div>

                            <div
                                v-if="selected.output === 'text'"
                                class="sp-chat-prose prose prose-sm max-h-[420px] max-w-none overflow-auto rounded-xl border border-soft bg-surface p-3 text-ink dark:prose-invert"
                                v-html="renderMarkdown(current.result.text)"
                            />

                            <div
                                v-else-if="selected.output === 'embeddings'"
                                class="rounded-xl border border-soft bg-surface p-3 text-sm text-ink"
                            >
                                <p class="font-medium">
                                    {{
                                        t('app_v2.playground.embeddings_dims', {
                                            n: current.result.dimensions ?? 0,
                                        })
                                    }}
                                </p>
                                <p class="mt-1 text-xs text-ink-muted">
                                    {{
                                        t(
                                            'app_v2.playground.embeddings_preview',
                                        )
                                    }}:
                                    {{
                                        (current.result.preview ?? []).join(
                                            ', ',
                                        )
                                    }}…
                                </p>
                            </div>

                            <img
                                v-else-if="selected.output === 'image'"
                                :src="current.result.image"
                                alt="result"
                                class="max-w-full rounded-xl border border-soft"
                            />

                            <audio
                                v-else-if="selected.output === 'audio'"
                                :src="current.result.audio"
                                controls
                                class="w-full"
                            />

                            <ol
                                v-else-if="selected.output === 'rerank'"
                                class="space-y-1.5"
                            >
                                <li
                                    v-for="(r, i) in current.result.ranked ??
                                    []"
                                    :key="i"
                                    class="rounded-lg border border-soft bg-surface p-2.5 text-sm text-ink"
                                >
                                    <span class="text-xs text-ink-subtle"
                                        >#{{ i + 1 }} · {{ r.score }}</span
                                    >
                                    <div class="break-words">
                                        {{ r.document }}
                                    </div>
                                </li>
                            </ol>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Run history -->
            <div class="space-y-3 rounded-2xl border border-soft bg-navy p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2
                            class="inline-flex items-center gap-2 text-base font-semibold text-ink"
                        >
                            <History class="size-4" />
                            {{ t('app_v2.playground.history') }}
                            <span
                                v-if="history.total > 0"
                                class="text-xs font-normal text-ink-subtle"
                            >
                                {{
                                    t(
                                        'app_v2.playground.history_total',
                                        { n: history.total },
                                        history.total,
                                    )
                                }}
                            </span>
                        </h2>
                        <p class="text-xs text-ink-muted">
                            {{ t('app_v2.playground.history_desc') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div
                            class="flex rounded-lg border border-medium p-0.5 text-xs"
                        >
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 transition-colors"
                                :class="
                                    history.onlyCurrent
                                        ? 'bg-accent-blue/15 font-medium text-accent-blue'
                                        : 'text-ink-muted hover:text-ink'
                                "
                                @click="toggleHistoryScope(true)"
                            >
                                {{ t('app_v2.playground.history_current') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 transition-colors"
                                :class="
                                    !history.onlyCurrent
                                        ? 'bg-accent-blue/15 font-medium text-accent-blue'
                                        : 'text-ink-muted hover:text-ink'
                                "
                                @click="toggleHistoryScope(false)"
                            >
                                {{ t('app_v2.playground.history_all') }}
                            </button>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-medium p-1.5 text-ink-muted transition-colors hover:text-ink"
                            :title="t('app_v2.playground.history_refresh')"
                            @click="loadHistory(history.page)"
                        >
                            <RefreshCw
                                class="size-3.5"
                                :class="{ 'animate-spin': history.loading }"
                            />
                        </button>
                    </div>
                </div>

                <p
                    v-if="!history.loading && history.items.length === 0"
                    class="py-6 text-center text-sm text-ink-subtle"
                >
                    {{ t('app_v2.playground.history_empty') }}
                </p>

                <ul v-else class="divide-y divide-soft">
                    <li v-for="item in history.items" :key="item.id">
                        <button
                            type="button"
                            class="grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 py-2.5 text-left transition-colors hover:bg-surface"
                            @click="toggleDetail(item.id)"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="{
                                    'bg-sp-success': item.status === 'ok',
                                    'bg-sp-danger': item.status === 'error',
                                    'animate-pulse bg-sp-warning':
                                        item.status === 'queued' ||
                                        item.status === 'running',
                                }"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-sm text-ink"
                                    :title="item.excerpt ?? undefined"
                                >
                                    {{
                                        item.excerpt ??
                                        t(
                                            `app_v2.playground.cap.${item.capability}`,
                                        )
                                    }}
                                </span>
                                <span
                                    class="block truncate text-[11px] text-ink-subtle"
                                >
                                    {{
                                        t(
                                            `app_v2.playground.cap.${item.capability}`,
                                        )
                                    }}
                                    <template v-if="item.model">
                                        · {{ item.driver }} · {{ item.model }}
                                    </template>
                                    <template v-if="item.served_by">
                                        ·
                                        {{
                                            t('app_v2.playground.served_by', {
                                                name: item.served_by,
                                            })
                                        }}
                                    </template>
                                    <template v-if="item.user">
                                        ·
                                        {{
                                            t('app_v2.playground.by_user', {
                                                name: item.user,
                                            })
                                        }}
                                    </template>
                                </span>
                            </span>
                            <span
                                class="flex shrink-0 items-center gap-3 text-[11px] text-ink-subtle tabular-nums"
                            >
                                <span
                                    v-if="item.duration_ms != null"
                                    class="inline-flex items-center gap-1"
                                >
                                    <Timer class="size-3" />
                                    {{ fmtDuration(item.duration_ms) }}
                                </span>
                                <span
                                    v-if="item.tokens_per_second != null"
                                    class="hidden items-center gap-1 sm:inline-flex"
                                    :title="
                                        t(
                                            'app_v2.playground.metrics_throughput',
                                        )
                                    "
                                >
                                    <Gauge class="size-3" />
                                    {{ item.tokens_per_second }}
                                    {{ t('app_v2.playground.metrics_tps') }}
                                </span>
                                <span v-if="item.total_tokens">
                                    {{
                                        t('app_v2.playground.tokens', {
                                            n: item.total_tokens.toLocaleString(),
                                        })
                                    }}
                                </span>
                                <span
                                    v-if="item.cost != null"
                                    class="text-ink-muted"
                                >
                                    {{ fmtCost(item.cost) }}
                                </span>
                                <span class="hidden sm:inline">
                                    {{ fmtTimestamp(item.created_at) }}
                                </span>
                                <ChevronDown
                                    class="size-3.5 transition-transform"
                                    :class="{
                                        'rotate-180': expandedId === item.id,
                                    }"
                                />
                            </span>
                        </button>

                        <!-- Expanded run detail -->
                        <div
                            v-if="expandedId === item.id"
                            class="space-y-3 border-t border-soft py-3 pl-5"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 font-mono text-[11px] text-ink-subtle transition-colors hover:text-ink"
                                :title="t('app_v2.playground.copy')"
                                @click="copyRunId(item.id)"
                            >
                                <Check
                                    v-if="copiedRunId === item.id"
                                    class="size-3 text-sp-success"
                                />
                                <Copy v-else class="size-3" />
                                {{ item.id }}
                            </button>
                            <p
                                v-if="detailLoading === item.id"
                                class="inline-flex items-center gap-2 text-xs text-ink-muted"
                            >
                                <Loader2 class="size-3.5 animate-spin" />
                                {{ t('app_v2.playground.running') }}
                            </p>
                            <template v-else-if="details[item.id]">
                                <!-- Model that served the run, prominent. -->
                                <div v-if="details[item.id]!.model">
                                    <p class="text-base font-semibold text-ink">
                                        {{ details[item.id]!.model }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        <template
                                            v-if="details[item.id]!.driver"
                                        >
                                            {{ details[item.id]!.driver }}
                                        </template>
                                        <template
                                            v-if="details[item.id]!.served_by"
                                        >
                                            ·
                                            {{
                                                t(
                                                    'app_v2.playground.served_by',
                                                    {
                                                        name: details[item.id]!
                                                            .served_by,
                                                    },
                                                )
                                            }}
                                        </template>
                                    </p>
                                </div>

                                <div
                                    v-if="details[item.id]!.error"
                                    class="rounded-xl border border-sp-danger/30 bg-sp-danger/10 p-3 text-sm text-sp-danger"
                                >
                                    {{ details[item.id]!.error }}
                                </div>

                                <RunMetrics
                                    v-if="details[item.id]!.metrics"
                                    :metrics="details[item.id]!.metrics!"
                                    detailed
                                />

                                <div
                                    v-if="details[item.id]!.input"
                                    class="space-y-1"
                                >
                                    <p
                                        class="text-[11px] font-medium text-ink-subtle uppercase"
                                    >
                                        {{
                                            t('app_v2.playground.detail_input')
                                        }}
                                    </p>
                                    <pre
                                        class="max-h-48 overflow-auto rounded-xl border border-soft bg-surface p-3 text-xs whitespace-pre-wrap text-ink"
                                        >{{
                                            fmtJson(details[item.id]!.input)
                                        }}</pre
                                    >
                                </div>

                                <div
                                    v-if="details[item.id]!.file_meta"
                                    class="text-xs text-ink-muted"
                                >
                                    {{ t('app_v2.playground.detail_file') }}:
                                    {{ details[item.id]!.file_meta!.name }}
                                    ({{ details[item.id]!.file_meta!.mime }},
                                    {{ details[item.id]!.file_meta!.size }}
                                    bytes)
                                </div>

                                <div
                                    v-if="details[item.id]!.output_text"
                                    class="space-y-1"
                                >
                                    <p
                                        class="text-[11px] font-medium text-ink-subtle uppercase"
                                    >
                                        {{
                                            t('app_v2.playground.detail_output')
                                        }}
                                    </p>
                                    <div
                                        class="sp-chat-prose prose prose-sm max-h-72 max-w-none overflow-auto rounded-xl border border-soft bg-surface p-3 text-ink dark:prose-invert"
                                        v-html="
                                            renderMarkdown(
                                                details[item.id]!.output_text,
                                            )
                                        "
                                    />
                                </div>

                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-[11px] text-ink-subtle transition-colors hover:text-ink"
                                    @click="
                                        rawJsonShown[item.id] =
                                            !rawJsonShown[item.id]
                                    "
                                >
                                    <Braces class="size-3" />
                                    {{
                                        rawJsonShown[item.id]
                                            ? t(
                                                  'app_v2.playground.detail_hide_json',
                                              )
                                            : t(
                                                  'app_v2.playground.detail_show_json',
                                              )
                                    }}
                                </button>

                                <div
                                    v-show="rawJsonShown[item.id]"
                                    class="space-y-3"
                                >
                                    <div
                                        v-if="details[item.id]!.output"
                                        class="space-y-1"
                                    >
                                        <p
                                            class="text-[11px] font-medium text-ink-subtle uppercase"
                                        >
                                            {{
                                                t(
                                                    'app_v2.playground.detail_output_data',
                                                )
                                            }}
                                        </p>
                                        <pre
                                            class="max-h-48 overflow-auto rounded-xl border border-soft bg-surface p-3 text-xs whitespace-pre-wrap text-ink"
                                            >{{
                                                fmtJson(
                                                    details[item.id]!.output,
                                                )
                                            }}</pre
                                        >
                                    </div>

                                    <div class="space-y-1">
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <p
                                                class="text-[11px] font-medium text-ink-subtle uppercase"
                                            >
                                                {{
                                                    t(
                                                        'app_v2.playground.detail_raw_json',
                                                    )
                                                }}
                                            </p>
                                            <button
                                                v-if="details[item.id]!.raw"
                                                type="button"
                                                class="inline-flex items-center gap-1 text-[11px] text-ink-subtle transition-colors hover:text-ink"
                                                @click="
                                                    copyText(
                                                        fmtJson(
                                                            details[item.id]!
                                                                .raw,
                                                        ),
                                                    )
                                                "
                                            >
                                                <Copy class="size-3" />
                                                {{
                                                    t('app_v2.playground.copy')
                                                }}
                                            </button>
                                        </div>
                                        <pre
                                            v-if="details[item.id]!.raw"
                                            class="max-h-72 overflow-auto rounded-xl border border-soft bg-surface p-3 text-xs whitespace-pre-wrap text-ink"
                                            >{{
                                                fmtJson(details[item.id]!.raw)
                                            }}</pre
                                        >
                                        <p
                                            v-else
                                            class="text-xs text-ink-subtle italic"
                                        >
                                            {{
                                                t(
                                                    'app_v2.playground.detail_raw_none',
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="details[item.id]!.response"
                                        class="space-y-1"
                                    >
                                        <p
                                            class="text-[11px] font-medium text-ink-subtle uppercase"
                                        >
                                            {{
                                                t(
                                                    'app_v2.playground.detail_response_json',
                                                )
                                            }}
                                        </p>
                                        <pre
                                            class="max-h-48 overflow-auto rounded-xl border border-soft bg-surface p-3 text-xs whitespace-pre-wrap text-ink"
                                            >{{
                                                fmtJson(
                                                    details[item.id]!.response,
                                                )
                                            }}</pre
                                        >
                                    </div>
                                </div>
                            </template>
                        </div>
                    </li>
                </ul>

                <!-- Pagination -->
                <div
                    v-if="history.lastPage > 1"
                    class="flex items-center justify-end gap-2 text-xs text-ink-muted"
                >
                    <span>
                        {{
                            t('app_v2.playground.history_page', {
                                page: history.page,
                                last: history.lastPage,
                            })
                        }}
                    </span>
                    <button
                        type="button"
                        :disabled="history.page <= 1 || history.loading"
                        class="rounded-md border border-medium p-1 transition-colors hover:text-ink disabled:opacity-40"
                        @click="loadHistory(history.page - 1)"
                    >
                        <ChevronLeft class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        :disabled="
                            history.page >= history.lastPage || history.loading
                        "
                        class="rounded-md border border-medium p-1 transition-colors hover:text-ink disabled:opacity-40"
                        @click="loadHistory(history.page + 1)"
                    >
                        <ChevronRight class="size-3.5" />
                    </button>
                </div>
            </div>
        </div>
    </AppLayoutV2>
</template>
