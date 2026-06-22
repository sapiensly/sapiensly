<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head } from '@inertiajs/vue3';
import { Loader2, Play } from '@lucide/vue';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

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

interface RunResult {
    ok: boolean;
    model?: string;
    text?: string;
    image?: string;
    audio?: string;
    dimensions?: number;
    preview?: number[];
    ranked?: { index: number; score: number; document: string }[];
    error?: string;
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
    file: File | null;
    running: boolean;
    result: RunResult | null;
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
            file: null,
            running: false,
            result: null,
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

async function run() {
    const cap = selected.value;
    const f = current.value;
    if (!cap || f.running) return;

    f.running = true;
    f.result = null;

    const fd = new FormData();
    fd.append('capability', cap.key);
    if (f.modelId) fd.append('model_id', f.modelId);

    if (cap.input === 'prompt' || cap.input === 'image_q')
        fd.append('prompt', cap.input === 'image_q' ? f.question : f.prompt);
    if (cap.input === 'text') fd.append('text', f.text);
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
        f.result = data;
    } catch (err) {
        const e = err as { response?: { data?: RunResult } };
        f.result = e.response?.data ?? {
            ok: false,
            error: t('app_v2.playground.error'),
        };
    } finally {
        f.running = false;
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
                                class="flex items-center justify-between text-xs text-ink-subtle"
                            >
                                <span>{{ t('app_v2.playground.result') }}</span>
                                <span>{{ current.result.model }}</span>
                            </div>

                            <pre
                                v-if="selected.output === 'text'"
                                class="max-h-[420px] overflow-auto rounded-xl border border-soft bg-surface p-3 text-sm whitespace-pre-wrap text-ink"
                                >{{ current.result.text }}</pre
                            >

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
        </div>
    </AppLayoutV2>
</template>
