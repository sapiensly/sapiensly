<script setup lang="ts">
import ArtifactWorkbench from '@/components/documents/ArtifactWorkbench.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import echo from '@/echo';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Code2, FileText, Type } from 'lucide-vue-next';
import type { Component } from 'vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { Folder, VisibilityOption } from '@/types/document';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ArrowLeft,
    Eye,
    FileUp,
    Globe,
    LoaderCircle,
    Lock,
    Maximize2,
    Minimize2,
    Pencil,
    Sparkles,
    Users,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface InlineDocumentType {
    value: string;
    label: string;
    extension: string;
}

interface ChatModelOption {
    value: string;
    label: string;
    provider: string;
}

interface Props {
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
    currentFolder: Folder | null;
    currentFolderId: string | null;
    inlineDocumentTypes: InlineDocumentType[];
    availableChatModels: ChatModelOption[];
    defaultChatModelId: string | null;
}

const props = defineProps<Props>();
const { t } = useI18n();

const form = useForm({
    type: 'md' as string,
    name: '',
    body: '',
    keywords: [] as string[],
    visibility: 'private',
    folder_id: props.currentFolderId,
    knowledge_base_id: null as string | null,
});

const activeTab = ref<'write' | 'import' | 'ai'>('write');
const writeMode = ref<'edit' | 'preview'>('edit');
// Flips to true after a successful artifact AI generation so the page
// transitions into the iterative workbench instead of the Write tab.
const workbenchOpen = ref(false);
// When the artifact generator hands the stream off to the workbench,
// this carries the streamId so the workbench can subscribe from mount.
const streamingArtifactId = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const importError = ref<string | null>(null);

const aiPrompt = ref('');
const aiGenerating = ref(false);
const aiError = ref<string | null>(null);
const aiModelId = ref<string | null>(
    props.defaultChatModelId ?? props.availableChatModels[0]?.value ?? null,
);

const previewPane = ref<HTMLElement | null>(null);
const isPreviewFullscreen = ref(false);

function togglePreviewFullscreen() {
    const el = previewPane.value;
    if (!el) return;
    if (document.fullscreenElement === el) {
        void document.exitFullscreen();
    } else {
        void el.requestFullscreen();
    }
}

function onFullscreenChange() {
    isPreviewFullscreen.value = document.fullscreenElement === previewPane.value;
}

onMounted(() => {
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullscreenChange);
});

const renderedMarkdown = computed(() =>
    DOMPurify.sanitize(
        marked.parse(form.body ?? '', { gfm: true, breaks: true }) as string,
        { ADD_ATTR: ['target'] },
    ),
);

// Icon + tint per inline document type, mirroring the AgentTypeSelector
// pattern: the same colour used on the icon tile is reused on the card's
// active border so the selection reads as "this tone = this type".
function typeIcon(value: string): Component {
    switch (value) {
        case 'md':
            return FileText;
        case 'artifact':
            return Code2;
        default:
            return Type;
    }
}

function typeTint(value: string): string {
    switch (value) {
        case 'md':
            return 'var(--sp-accent-blue)';
        case 'artifact':
            return 'var(--sp-spectrum-magenta)';
        default:
            return 'var(--sp-accent-cyan)';
    }
}

const aiPromptPlaceholder = computed(() => {
    switch (form.type) {
        case 'md':
            return t('documents.create.ai.prompt_placeholder_md');
        case 'artifact':
            return t('documents.create.ai.prompt_placeholder_artifact');
        default:
            return t('documents.create.ai.prompt_placeholder_txt');
    }
});

// Switching document type wipes everything the user has authored so
// content written for one type (e.g. a Markdown outline) doesn't get
// carried over verbatim into another (e.g. an HTML artifact).
watch(
    () => form.type,
    () => {
        form.name = '';
        form.body = '';
        form.keywords = [];
        form.visibility = 'private';
        aiPrompt.value = '';
        aiError.value = null;
        importError.value = null;
        writeMode.value = 'edit';
        workbenchOpen.value = false;
        streamingArtifactId.value = null;
        // Tear down any in-flight stream subscription — we don't want
        // chunks from the previous type leaking into the reset body.
        if (aiGenerating.value) {
            aiGenerating.value = false;
            teardownStream();
        }
    },
);

const acceptForType = computed(() => {
    switch (form.type) {
        case 'md':
            return '.md,.markdown,.txt';
        case 'artifact':
            return '.html,.htm';
        default:
            return '.txt';
    }
});

const placeholderForType = computed(() => {
    switch (form.type) {
        case 'md':
            return '# Title\n\nParagraph...\n\n```mermaid\ngraph TD;\n  A-->B;\n```';
        case 'artifact':
            return '<!doctype html>\n<html>\n  <body>\n    <h1>Hello</h1>\n    <script>console.log("running in sandbox");<\/script>\n  </body>\n</html>';
        default:
            return 'Type the document content here…';
    }
});

async function onFileSelected(e: Event) {
    const target = e.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) return;

    const MAX = 10 * 1024 * 1024;
    if (file.size > MAX) {
        importError.value = t('documents.create.file_too_large');
        return;
    }

    try {
        const text = await file.text();
        form.body = text;
        if (!form.name) {
            form.name = file.name.replace(/\.[^/.]+$/, '');
        }
        activeTab.value = 'write';
    } catch {
        importError.value = t('documents.create.read_error');
    } finally {
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
}

function submit() {
    form.post('/documents/inline');
}

function closeWorkbench() {
    // Parent-level reset whenever the user backs out of / discards the
    // artifact workbench: drop the stream handoff id so we don't leak
    // a dead subscription on the next generation.
    workbenchOpen.value = false;
    streamingArtifactId.value = null;
}

// Active stream channel + safety timeout, so we can tear them down on
// success, error, component unmount, or if the server goes silent.
let activeStreamChannel: string | null = null;
let streamSafetyTimer: ReturnType<typeof setTimeout> | null = null;

function teardownStream() {
    if (streamSafetyTimer) {
        clearTimeout(streamSafetyTimer);
        streamSafetyTimer = null;
    }
    if (activeStreamChannel) {
        echo.leave(activeStreamChannel);
        activeStreamChannel = null;
    }
}

onBeforeUnmount(() => {
    teardownStream();
});

async function generateWithAi() {
    if (aiGenerating.value || aiPrompt.value.trim().length < 4) return;

    aiGenerating.value = true;
    aiError.value = null;
    // Clear any previous body so the user sees the stream write from
    // scratch rather than replacing an old generation.
    form.body = '';

    try {
        const { data } = await axios.post<{
            success: boolean;
            streamId?: string;
            message?: string;
            detail?: string;
        }>('/documents/generate', {
            type: form.type,
            prompt: aiPrompt.value,
            modelId: aiModelId.value,
        });

        if (!data.success || !data.streamId) {
            const base =
                data.message ?? t('documents.create.ai.generation_failed');
            aiError.value = data.detail ? `${base} — ${data.detail}` : base;
            aiGenerating.value = false;
            return;
        }

        if (form.type === 'artifact') {
            // Hand the stream off to the workbench — it subscribes on
            // mount and shows the live code-typing view. We flip the
            // flag right away so the user sees the transition instead
            // of staring at a spinning button.
            streamingArtifactId.value = data.streamId;
            workbenchOpen.value = true;
            aiGenerating.value = false;
        } else {
            subscribeToGenerationStream(data.streamId);
        }
    } catch (err) {
        const axiosErr = err as {
            response?: {
                status?: number;
                data?: { message?: string; detail?: string } | string;
            };
        };
        const data = axiosErr?.response?.data;
        const serverPayload =
            typeof data === 'object' && data !== null ? data : undefined;
        const base =
            serverPayload?.message ??
            (err instanceof Error ? err.message : '') ??
            t('documents.create.ai.generation_failed');
        aiError.value = serverPayload?.detail
            ? `${base} — ${serverPayload.detail}`
            : base || t('documents.create.ai.generation_failed');
        aiGenerating.value = false;
    }
}

function subscribeToGenerationStream(streamId: string) {
    const channelName = `documents.stream.${streamId}`;
    activeStreamChannel = channelName;

    // Safety net: if the job never broadcasts (worker not running, Reverb
    // unreachable, …), surface a clear error after 10 minutes so the UI
    // doesn't sit pending forever.
    streamSafetyTimer = setTimeout(
        () => {
            aiError.value = t('documents.create.ai.stream_unavailable');
            aiGenerating.value = false;
            teardownStream();
        },
        10 * 60 * 1000,
    );

    const channel = echo.private(channelName);

    channel.listen('.DocumentStreamChunk', (payload: { content: string }) => {
        if (typeof payload?.content === 'string') {
            form.body += payload.content;
        }
    });

    channel.listen('.DocumentStreamComplete', () => {
        // The accumulated chunks ARE the final body — Reverb delivers
        // events in order, so we don't need the complete event to
        // re-ship the whole payload (it would blow past Reverb's max
        // request size on non-trivial artifacts).
        if (!form.name && form.type !== 'artifact') {
            const firstLine =
                form.body
                    .split('\n')
                    .map((l) => l.replace(/^#+\s*/, '').trim())
                    .find((l) => l.length > 0) ?? '';
            if (firstLine) {
                form.name = firstLine.slice(0, 80);
            }
        }

        if (form.type === 'artifact') {
            workbenchOpen.value = true;
        } else {
            activeTab.value = 'write';
        }

        aiGenerating.value = false;
        teardownStream();
    });

    channel.listen('.DocumentStreamError', (payload: { error: string }) => {
        aiError.value =
            payload?.error || t('documents.create.ai.generation_failed');
        aiGenerating.value = false;
        teardownStream();
    });
}

const backHref = computed(() =>
    props.currentFolderId
        ? `/documents?folder=${props.currentFolderId}`
        : '/documents',
);

// Public visibility is allowed only for Artifact documents; filter the option
// out for other types so it can't be chosen.
const availableVisibilityOptions = computed(() =>
    props.visibilityOptions.filter(
        (o) => o.value !== 'public' || form.type === 'artifact',
    ),
);

function visibilityIcon(value: string) {
    return value === 'public' ? Globe : value === 'organization' ? Users : Lock;
}

</script>

<template>
    <Head :title="t('documents.create.title')" />

    <AppLayoutV2 :title="t('documents.create.title')">
        <!-- Workbench mode — fills the content area; full width. -->
        <div
            v-if="workbenchOpen"
            class="flex h-[calc(100vh-9rem)] w-full flex-col"
        >
            <div class="mb-3">
                <!--
                  Artifact isn't persisted yet, so "back" lands the user
                  on the type picker instead of the documents listing —
                  losing the draft requires an explicit Descartar.
                -->
                <button
                    type="button"
                    class="inline-flex items-center gap-1 text-[11px] text-ink-muted transition-colors hover:text-ink"
                    @click="closeWorkbench"
                >
                    <ArrowLeft class="size-3" />
                    {{ t('documents.workbench.back_to_create') }}
                </button>
            </div>
            <div class="min-h-0 flex-1">
                <ArtifactWorkbench
                    save-mode="create"
                    :initial-body="form.body"
                    :initial-name="form.name"
                    :visibility-options="visibilityOptions"
                    :can-share-with-org="canShareWithOrg"
                    :current-folder-id="currentFolderId"
                    :initial-keywords="form.keywords"
                    :initial-visibility="form.visibility"
                    :available-chat-models="availableChatModels"
                    :default-chat-model-id="aiModelId ?? defaultChatModelId"
                    :initial-stream-id="streamingArtifactId"
                    @discard="closeWorkbench"
                />
            </div>
        </div>

        <div v-else class="mx-auto w-full max-w-3xl space-y-5">
            <header class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <Link
                        :href="backHref"
                        class="inline-flex items-center gap-1 text-[11px] text-ink-muted transition-colors hover:text-ink"
                    >
                        <ArrowLeft class="size-3" />
                        {{
                            currentFolder
                                ? currentFolder.name
                                : t('app_v2.documents.heading')
                        }}
                    </Link>
                    <h1 class="text-[22px] font-semibold leading-tight text-ink">
                        {{ t('documents.create.title') }}
                    </h1>
                    <p class="text-xs text-ink-muted">
                        {{ t('documents.create.description') }}
                    </p>
                </div>
            </header>

            <form
                class="space-y-5 rounded-sp-sm border border-soft bg-navy p-5"
                @submit.prevent="submit"
            >
                <div class="space-y-1.5">
                    <Label>{{ t('documents.create.type') }}</Label>
                    <div
                        role="radiogroup"
                        :aria-label="t('documents.create.type')"
                        class="grid gap-3 sm:grid-cols-3"
                    >
                        <button
                            v-for="opt in props.inlineDocumentTypes"
                            :key="opt.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.type === opt.value"
                            :class="[
                                'flex items-center gap-3 rounded-sp-sm border px-3 py-2.5 text-left transition-colors',
                                form.type === opt.value
                                    ? 'bg-white/[0.06]'
                                    : 'border-soft bg-white/[0.03] hover:border-accent-blue/30 hover:bg-white/[0.06]',
                            ]"
                            :style="
                                form.type === opt.value
                                    ? {
                                          borderColor: `color-mix(in oklab, ${typeTint(opt.value)} 55%, transparent)`,
                                      }
                                    : {}
                            "
                            @click="form.type = opt.value"
                        >
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, ${typeTint(opt.value)} 15%, transparent)`,
                                    color: typeTint(opt.value),
                                }"
                            >
                                <component
                                    :is="typeIcon(opt.value)"
                                    class="size-4"
                                />
                            </div>
                            <div class="min-w-0 space-y-0.5">
                                <h3 class="text-[13px] leading-tight font-semibold text-ink">
                                    {{ opt.label }}
                                </h3>
                                <p class="text-[11px] leading-snug text-ink-muted">
                                    {{ t(`documents.create.type_desc.${opt.value}`) }}
                                </p>
                            </div>
                        </button>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <Label for="doc-name">{{
                        t('documents.create.name')
                    }}</Label>
                    <Input
                        id="doc-name"
                        v-model="form.name"
                        :placeholder="t('documents.create.name_placeholder')"
                    />
                </div>

                <Tabs v-model="activeTab" class="w-full">
                    <TabsList>
                        <TabsTrigger value="write">
                            <Pencil class="mr-1.5 size-3.5" />
                            {{ t('documents.create.write_tab') }}
                        </TabsTrigger>
                        <TabsTrigger value="import">
                            <FileUp class="mr-1.5 size-3.5" />
                            {{ t('documents.create.import_tab') }}
                        </TabsTrigger>
                        <TabsTrigger value="ai">
                            <Sparkles class="mr-1.5 size-3.5" />
                            {{ t('documents.create.ai_tab') }}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="write" class="mt-3 space-y-2">
                        <div
                            v-if="form.body"
                            class="flex items-center justify-end"
                        >
                            <div
                                role="tablist"
                                class="inline-flex rounded-pill border border-soft bg-white/5 p-0.5 text-[11px] font-medium"
                            >
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="writeMode === 'edit'"
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-pill px-2.5 py-1 transition-colors',
                                        writeMode === 'edit'
                                            ? 'bg-accent-blue/15 text-ink'
                                            : 'text-ink-muted hover:text-ink',
                                    ]"
                                    @click="writeMode = 'edit'"
                                >
                                    <Pencil class="size-3" />
                                    {{ t('documents.create.edit') }}
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="writeMode === 'preview'"
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-pill px-2.5 py-1 transition-colors',
                                        writeMode === 'preview'
                                            ? 'bg-accent-blue/15 text-ink'
                                            : 'text-ink-muted hover:text-ink',
                                    ]"
                                    @click="writeMode = 'preview'"
                                >
                                    <Eye class="size-3" />
                                    {{ t('documents.create.preview') }}
                                </button>
                            </div>
                        </div>

                        <textarea
                            v-if="writeMode === 'edit' || !form.body"
                            v-model="form.body"
                            :placeholder="placeholderForType"
                            rows="18"
                            class="w-full rounded-xs border border-medium bg-white/5 p-3 font-mono text-sm text-ink placeholder:text-ink-subtle focus-visible:border-accent-blue focus-visible:ring-3 focus-visible:ring-accent-blue/25 focus-visible:outline-none"
                            spellcheck="false"
                        />

                        <div
                            v-else
                            ref="previewPane"
                            :class="[
                                'relative overflow-hidden rounded-xs border border-medium bg-white/[0.02]',
                                isPreviewFullscreen ? 'h-screen' : 'h-[432px]',
                            ]"
                        >
                            <!-- Markdown + text previews scroll inside the box;
                                 the artifact iframe keeps its sandbox and
                                 scrolls internally on its own. -->
                            <div
                                v-if="form.type === 'md'"
                                class="prose prose-sm prose-invert h-full max-w-none overflow-y-auto p-4"
                                v-html="renderedMarkdown"
                            />
                            <iframe
                                v-else-if="form.type === 'artifact'"
                                :srcdoc="form.body"
                                sandbox="allow-scripts"
                                class="h-full w-full border-0 bg-white"
                            />
                            <pre
                                v-else
                                class="h-full overflow-auto p-4 font-mono text-sm whitespace-pre-wrap text-ink"
                                >{{ form.body }}</pre
                            >

                            <button
                                type="button"
                                class="absolute top-2 right-2 z-10 inline-flex size-7 items-center justify-center rounded-xs border border-soft bg-navy/70 text-ink-muted backdrop-blur transition-colors hover:bg-navy hover:text-ink"
                                :aria-label="
                                    isPreviewFullscreen
                                        ? t('documents.create.preview_exit_fullscreen')
                                        : t('documents.create.preview_fullscreen')
                                "
                                @click="togglePreviewFullscreen"
                            >
                                <Minimize2
                                    v-if="isPreviewFullscreen"
                                    class="size-3.5"
                                />
                                <Maximize2 v-else class="size-3.5" />
                            </button>
                        </div>

                        <p class="text-xs text-ink-muted">
                            {{ t('documents.create.body_hint') }}
                        </p>
                    </TabsContent>

                    <TabsContent value="import" class="mt-3">
                        <label
                            class="flex cursor-pointer flex-col items-center justify-center rounded-xs border-2 border-dashed border-medium bg-white/[0.02] p-8 text-center transition-colors hover:border-accent-blue/50 hover:bg-white/[0.04]"
                        >
                            <input
                                ref="fileInput"
                                type="file"
                                class="hidden"
                                :accept="acceptForType"
                                @change="onFileSelected"
                            />
                            <FileUp class="mb-2 size-8 text-ink-muted" />
                            <span class="text-sm text-ink">
                                {{ t('documents.create.import_cta') }}
                            </span>
                            <span class="mt-1 font-mono text-xs text-ink-subtle">
                                {{ acceptForType }}
                            </span>
                        </label>
                        <p
                            v-if="importError"
                            class="mt-2 text-xs text-sp-danger"
                        >
                            {{ importError }}
                        </p>
                    </TabsContent>

                    <TabsContent value="ai" class="mt-3 space-y-3">
                        <div
                            v-if="availableChatModels.length > 0"
                            class="space-y-1.5"
                        >
                            <Label for="ai-model">
                                {{ t('documents.create.ai.model_label') }}
                            </Label>
                            <Select v-model="aiModelId" :disabled="aiGenerating">
                                <SelectTrigger id="ai-model" class="w-full">
                                    <SelectValue
                                        :placeholder="t('documents.create.ai.model_placeholder')"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="m in availableChatModels"
                                        :key="m.value"
                                        :value="m.value"
                                    >
                                        {{ m.label }}
                                        <span class="ml-1 text-[10px] text-ink-subtle">
                                            · {{ m.provider }}
                                        </span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-1.5">
                            <Label for="ai-prompt">
                                {{ t('documents.create.ai.prompt_label') }}
                            </Label>
                            <textarea
                                id="ai-prompt"
                                v-model="aiPrompt"
                                :placeholder="aiPromptPlaceholder"
                                rows="5"
                                class="w-full rounded-xs border border-medium bg-white/5 p-3 text-sm text-ink placeholder:text-ink-subtle focus-visible:border-accent-blue focus-visible:ring-3 focus-visible:ring-accent-blue/25 focus-visible:outline-none"
                                :disabled="aiGenerating"
                            />
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            <p
                                v-if="aiError"
                                class="mr-auto text-xs text-sp-danger"
                            >
                                {{ aiError }}
                            </p>
                            <button
                                type="button"
                                :disabled="
                                    aiGenerating || aiPrompt.trim().length < 4
                                "
                                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                                @click="generateWithAi"
                            >
                                <LoaderCircle
                                    v-if="aiGenerating"
                                    class="size-3.5 animate-spin"
                                />
                                <Sparkles v-else class="size-3.5" />
                                {{
                                    aiGenerating
                                        ? t('documents.create.ai.generating')
                                        : t('documents.create.ai.generate')
                                }}
                            </button>
                        </div>

                        <p
                            v-if="aiGenerating"
                            class="text-right text-[11px] text-ink-subtle"
                        >
                            {{ t('documents.create.ai.long_wait_note') }}
                        </p>
                    </TabsContent>
                </Tabs>

                <div class="space-y-1.5">
                    <Label for="doc-keywords">{{
                        t('documents.create.keywords')
                    }}</Label>
                    <KeywordsInput v-model="form.keywords" />
                </div>

                <div class="space-y-1.5">
                    <Label for="doc-visibility">{{
                        t('documents.create.visibility')
                    }}</Label>
                    <Select v-model="form.visibility">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in availableVisibilityOptions"
                                :key="option.value"
                                :value="option.value"
                                :disabled="
                                    option.value === 'organization' &&
                                    !canShareWithOrg
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <component
                                        :is="visibilityIcon(option.value)"
                                        class="size-4"
                                    />
                                    {{ option.label }}
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="
                            availableVisibilityOptions.find(
                                (o) => o.value === form.visibility,
                            )?.description
                        "
                        class="text-xs text-ink-muted"
                    >
                        {{
                            availableVisibilityOptions.find(
                                (o) => o.value === form.visibility,
                            )?.description
                        }}
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-soft pt-4">
                    <Link
                        :href="backHref"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    >
                        {{ t('common.cancel') }}
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing || !form.body || !form.name"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-3.5 animate-spin"
                        />
                        {{ t('documents.create.submit') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayoutV2>
</template>
