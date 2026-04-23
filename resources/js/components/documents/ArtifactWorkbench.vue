<script setup lang="ts">
import ArtifactVisualEditor from '@/components/documents/ArtifactVisualEditor.vue';
import HtmlCodeEditor from '@/components/documents/HtmlCodeEditor.vue';
import KeywordsInput from '@/components/KeywordsInput.vue';
import echo from '@/echo';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { VisibilityOption } from '@/types/document';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Bot,
    Code,
    Eye,
    LoaderCircle,
    Send,
    Settings2,
    Sparkles,
    User,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface ChatTurn {
    role: 'user' | 'assistant';
    content: string;
}

interface ChatModelOption {
    value: string;
    label: string;
    provider: string;
}

interface Props {
    saveMode: 'create' | 'update';
    initialBody: string;
    initialName?: string;
    documentId?: string | null;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
    currentFolderId?: string | null;
    initialVisibility?: string;
    initialKeywords?: string[];
    availableChatModels?: ChatModelOption[];
    defaultChatModelId?: string | null;
    /**
     * When passed, the workbench opens directly into a streaming view
     * that subscribes to the matching Reverb channel. As chunks arrive
     * they accumulate in `body` and the code-rolling view shows them
     * typing in live. Once DocumentStreamComplete fires the view flips
     * to the normal tabs / iframe layout with the final artifact.
     */
    initialStreamId?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    initialName: '',
    documentId: null,
    currentFolderId: null,
    initialVisibility: 'private',
    initialKeywords: () => [],
    availableChatModels: () => [],
    defaultChatModelId: null,
    initialStreamId: null,
});

const emit = defineEmits<{
    (e: 'discard'): void;
}>();

const { t } = useI18n();

// Source of truth — every mode reads/writes this.
const body = ref(props.initialBody);
const name = ref(props.initialName);
const keywords = ref<string[]>([...props.initialKeywords]);
const visibility = ref(props.initialVisibility);

// Mode tabs. Default is AI since that's the primary refinement loop.
const mode = ref<'ai' | 'visual' | 'code'>('ai');

// Initial-generation streaming — active only when the parent passes a
// streamId (i.e. the user just clicked Generate and we're watching the
// LLM type the first HTML into existence). Flips false once the
// DocumentStreamComplete event lands; the rest of the workbench UI
// (tabs, iframe, chat) is hidden while this is true.
const isStreaming = ref<boolean>(!!props.initialStreamId);
const streamError = ref<string | null>(null);
const streamStartedAt = ref<number>(0);
const streamNow = ref<number>(0);
const streamScroll = ref<HTMLElement | null>(null);
let streamTickTimer: ReturnType<typeof setInterval> | null = null;

// Per-turn AI model pick. Defaults to the user's default chat model;
// lets the user flip between, say, a fast Haiku for quick tweaks and a
// bigger Sonnet for structural changes without leaving the workbench.
const aiModelId = ref<string | null>(
    props.defaultChatModelId ?? props.availableChatModels[0]?.value ?? null,
);

// Chat state. Seed with a single assistant bubble so the pane isn't blank.
const messages = ref<ChatTurn[]>([
    { role: 'assistant', content: t('documents.workbench.ai.seed') },
]);
const chatInput = ref('');
const chatPending = ref(false);
const chatError = ref<string | null>(null);
const chatScroll = ref<HTMLElement | null>(null);

const optionsOpen = ref(false);
const saveError = ref<string | null>(null);
const saving = ref(false);

// ────────────────────────────────────────────────────────────────────────
// Chat

// Streaming state. The refinement LLM call is dispatched as a queued
// job; the server broadcasts text deltas over a one-off Reverb channel,
// which we accumulate into `body` so the iframe updates live.
let activeStreamChannel: string | null = null;
let streamSafetyTimer: ReturnType<typeof setTimeout> | null = null;
let streamingBuffer = '';

function teardownStream() {
    if (streamSafetyTimer) {
        clearTimeout(streamSafetyTimer);
        streamSafetyTimer = null;
    }
    if (activeStreamChannel) {
        echo.leave(activeStreamChannel);
        activeStreamChannel = null;
    }
    streamingBuffer = '';
}

onBeforeUnmount(() => {
    teardownStream();
    teardownInitialStream();
});

// ────────────────────────────────────────────────────────────────────────
// Initial-generation stream — drives the "watch the AI write the code"
// view the user sees right after clicking Generate. Separate from the
// refinement stream machinery above because it has a different lifecycle
// (one-shot, unmounts on completion).

let initialStreamChannel: string | null = null;
let initialStreamTimer: ReturnType<typeof setTimeout> | null = null;

function subscribeToInitialStream(streamId: string) {
    const channelName = `documents.stream.${streamId}`;
    initialStreamChannel = channelName;
    body.value = '';
    streamError.value = null;
    streamStartedAt.value = Date.now();
    streamNow.value = Date.now();

    // Tick every 500ms so the elapsed counter in the header updates live.
    streamTickTimer = setInterval(() => {
        streamNow.value = Date.now();
    }, 500);

    // 10-minute safety net: if the job never broadcasts (worker down,
    // Reverb unreachable) surface a clear message so the user isn't
    // stuck watching an empty code pane forever.
    initialStreamTimer = setTimeout(
        () => {
            streamError.value = t('documents.create.ai.stream_unavailable');
            isStreaming.value = false;
            teardownInitialStream();
        },
        10 * 60 * 1000,
    );

    const channel = echo.private(channelName);

    channel.listen('.DocumentStreamChunk', async (payload: { content: string }) => {
        if (typeof payload?.content !== 'string') return;
        body.value += payload.content;
        await nextTick();
        if (streamScroll.value) {
            streamScroll.value.scrollTop = streamScroll.value.scrollHeight;
        }
    });

    channel.listen('.DocumentStreamComplete', () => {
        // `body` was accumulated from DocumentStreamChunk deltas; the
        // complete event carries no payload (Reverb max request size
        // would reject the full artifact). Just flip the UI.
        isStreaming.value = false;
        teardownInitialStream();
    });

    channel.listen('.DocumentStreamError', (payload: { error: string }) => {
        streamError.value =
            payload?.error || t('documents.create.ai.generation_failed');
        isStreaming.value = false;
        teardownInitialStream();
    });
}

function teardownInitialStream() {
    if (initialStreamTimer) {
        clearTimeout(initialStreamTimer);
        initialStreamTimer = null;
    }
    if (streamTickTimer) {
        clearInterval(streamTickTimer);
        streamTickTimer = null;
    }
    if (initialStreamChannel) {
        echo.leave(initialStreamChannel);
        initialStreamChannel = null;
    }
}

onMounted(() => {
    if (props.initialStreamId) {
        subscribeToInitialStream(props.initialStreamId);
    }
});

const streamElapsedSeconds = computed(() => {
    if (!streamStartedAt.value) return 0;
    return Math.max(0, Math.floor((streamNow.value - streamStartedAt.value) / 1000));
});

async function sendMessage() {
    const text = chatInput.value.trim();
    if (!text || chatPending.value) return;

    chatError.value = null;
    chatPending.value = true;

    // Snapshot history BEFORE appending the new turn — that's what the
    // server sees as context. The server also receives currentBody.
    const history = messages.value.map((m) => ({
        role: m.role,
        content: m.content,
    }));
    const instruction = text;

    messages.value.push({ role: 'user', content: instruction });
    chatInput.value = '';
    await nextTick();
    scrollChatToBottom();

    try {
        const { data } = await axios.post<{
            success: boolean;
            streamId?: string;
            message?: string;
            detail?: string;
        }>('/documents/refine', {
            type: 'artifact',
            history,
            instruction,
            currentBody: body.value,
            modelId: aiModelId.value,
        });

        if (!data.success || !data.streamId) {
            const base =
                data.message ?? t('documents.create.ai.generation_failed');
            chatError.value = data.detail ? `${base} — ${data.detail}` : base;
            // Roll back the user's turn so retry doesn't double-post it.
            messages.value.pop();
            chatPending.value = false;
            return;
        }

        subscribeToRefineStream(data.streamId);
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
        chatError.value = serverPayload?.detail
            ? `${base} — ${serverPayload.detail}`
            : base || t('documents.create.ai.generation_failed');
        messages.value.pop();
        chatPending.value = false;
    }
}

function subscribeToRefineStream(streamId: string) {
    const channelName = `documents.stream.${streamId}`;
    activeStreamChannel = channelName;
    streamingBuffer = '';

    streamSafetyTimer = setTimeout(
        () => {
            chatError.value = t('documents.create.ai.stream_unavailable');
            messages.value.pop();
            chatPending.value = false;
            teardownStream();
        },
        10 * 60 * 1000,
    );

    const channel = echo.private(channelName);

    channel.listen('.DocumentStreamChunk', (payload: { content: string }) => {
        if (typeof payload?.content !== 'string') return;
        streamingBuffer += payload.content;
        // Mirror the stream into the live body so the iframe preview
        // progressively renders the new artifact.
        body.value = streamingBuffer;
    });

    channel.listen('.DocumentStreamComplete', async () => {
        // `body` was accumulated live from chunks; the complete event
        // carries no payload so Reverb doesn't reject a large refined
        // artifact with "payload too large".
        messages.value.push({
            role: 'assistant',
            content: t('documents.workbench.ai.updated'),
        });
        chatPending.value = false;
        teardownStream();
        await nextTick();
        scrollChatToBottom();
    });

    channel.listen('.DocumentStreamError', (payload: { error: string }) => {
        chatError.value =
            payload?.error || t('documents.create.ai.generation_failed');
        messages.value.pop();
        chatPending.value = false;
        teardownStream();
    });
}

function scrollChatToBottom() {
    if (!chatScroll.value) return;
    chatScroll.value.scrollTop = chatScroll.value.scrollHeight;
}

// ────────────────────────────────────────────────────────────────────────
// Save / Discard

// Must match the backend validator (Store/UpdateInlineDocumentRequest
// + DocumentController::refine's `currentBody`). Bumping one side means
// bumping both — keep them in sync.
const MAX_BODY_BYTES = 10 * 1024 * 1024;

function save() {
    if (saving.value) return;
    saveError.value = null;

    // Fail fast client-side instead of waiting for the 422 round-trip —
    // a 10 MB-plus artifact is almost always unintentional (a paste gone
    // wrong) and waiting for the server to reject it is annoying.
    if (new Blob([body.value]).size > MAX_BODY_BYTES) {
        saveError.value = t('documents.workbench.body_too_large');
        return;
    }

    saving.value = true;

    if (props.saveMode === 'create') {
        const form = useForm({
            type: 'artifact',
            name: name.value,
            body: body.value,
            keywords: keywords.value,
            visibility: visibility.value,
            folder_id: props.currentFolderId,
            knowledge_base_id: null,
        });
        form.post('/documents/inline', {
            onError: () => {
                saveError.value = t('documents.workbench.save_failed');
            },
            onFinish: () => {
                saving.value = false;
            },
        });
    } else if (props.documentId) {
        const form = useForm({
            name: name.value,
            body: body.value,
            keywords: keywords.value,
        });
        form.patch(`/documents/${props.documentId}/inline`, {
            onError: () => {
                saveError.value = t('documents.workbench.save_failed');
            },
            onFinish: () => {
                saving.value = false;
            },
        });
    }
}

function discard() {
    if (props.saveMode === 'update' && props.documentId) {
        router.visit(`/documents/${props.documentId}`);
    } else {
        emit('discard');
    }
}

// Public visibility is only valid for Artifact documents — it always is
// here, but filter to keep the picker consistent with the other forms.
const availableVisibilityOptions = computed(() =>
    props.visibilityOptions.filter(
        (o) => o.value !== 'organization' || props.canShareWithOrg,
    ),
);

const canSave = computed(
    () => name.value.trim().length > 0 && body.value.length > 0 && !saving.value,
);
</script>

<template>
    <!-- Live code-streaming view while the initial LLM job is running. -->
    <section
        v-if="isStreaming"
        class="flex h-full min-h-0 flex-col overflow-hidden rounded-sp-sm border border-soft bg-navy"
    >
        <header class="flex items-center gap-3 border-b border-soft px-5 py-3">
            <LoaderCircle class="size-4 shrink-0 animate-spin text-accent-blue" />
            <div class="min-w-0 leading-tight">
                <p class="text-sm font-medium text-ink">
                    {{ t('documents.workbench.streaming.title') }}
                </p>
                <p class="text-[11px] text-ink-muted">
                    {{ t('documents.workbench.streaming.subtitle') }}
                </p>
            </div>
            <div class="ml-auto flex items-center gap-3 font-mono text-[11px] text-ink-subtle">
                <span>{{ body.length.toLocaleString() }} chars</span>
                <span class="text-ink-faint">·</span>
                <span>{{ streamElapsedSeconds }}s</span>
            </div>
        </header>
        <div
            ref="streamScroll"
            class="flex-1 overflow-auto bg-navy-deep p-5"
        >
            <pre
                class="font-mono text-[12px] leading-relaxed text-ink whitespace-pre-wrap break-all"
                >{{ body || t('documents.workbench.streaming.waiting') }}<span
                    v-if="body"
                    class="ml-0.5 inline-block h-3 w-1.5 animate-pulse bg-accent-blue align-middle"
                /></pre>
        </div>
    </section>

    <!-- Streaming errored out — still full-width, nothing to save yet. -->
    <section
        v-else-if="streamError"
        class="flex h-full min-h-0 flex-col items-center justify-center rounded-sp-sm border border-sp-danger/30 bg-sp-danger/5 p-8 text-center"
    >
        <p class="text-sm font-medium text-sp-danger">
            {{ t('documents.create.ai.generation_failed') }}
        </p>
        <p class="mt-1 text-xs text-ink-muted">
            {{ streamError }}
        </p>
        <button
            type="button"
            class="mt-4 inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
            @click="discard"
        >
            {{ t('documents.workbench.discard') }}
        </button>
    </section>

    <div v-else class="flex h-full min-h-0 flex-col gap-4">
        <!-- Top bar: name + save / discard / options -->
        <header class="flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[240px] space-y-1">
                <Label for="artifact-name" class="sr-only">
                    {{ t('documents.create.name') }}
                </Label>
                <Input
                    id="artifact-name"
                    v-model="name"
                    :placeholder="t('documents.create.name_placeholder')"
                    class="h-10 text-sm"
                />
            </div>

            <div class="relative flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    @click="optionsOpen = !optionsOpen"
                >
                    <Settings2 class="size-3.5" />
                    {{ t('documents.workbench.options') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    @click="discard"
                >
                    {{ t('documents.workbench.discard') }}
                </button>
                <button
                    type="button"
                    :disabled="!canSave"
                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                    @click="save"
                >
                    <LoaderCircle
                        v-if="saving"
                        class="size-3.5 animate-spin"
                    />
                    {{ t('documents.workbench.save') }}
                </button>

                <!-- Options popover -->
                <div
                    v-if="optionsOpen"
                    class="absolute right-0 top-full z-20 mt-2 w-80 space-y-3 rounded-sp-sm border border-soft bg-navy p-4 shadow-sp-float"
                >
                    <div class="space-y-1.5">
                        <Label for="artifact-visibility">
                            {{ t('documents.create.visibility') }}
                        </Label>
                        <Select v-model="visibility">
                            <SelectTrigger id="artifact-visibility" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in availableVisibilityOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="artifact-keywords">
                            {{ t('documents.create.keywords') }}
                        </Label>
                        <KeywordsInput v-model="keywords" />
                    </div>
                </div>
            </div>
        </header>

        <p v-if="saveError" class="text-xs text-sp-danger">{{ saveError }}</p>

        <!-- Mode tabs -->
        <div class="inline-flex rounded-pill border border-soft bg-white/5 p-0.5 text-[11px] font-medium self-start">
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-pill px-3 py-1 transition-colors',
                    mode === 'ai'
                        ? 'bg-accent-blue/15 text-ink'
                        : 'text-ink-muted hover:text-ink',
                ]"
                @click="mode = 'ai'"
            >
                <Sparkles class="size-3" />
                {{ t('documents.workbench.mode.ai') }}
            </button>
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-pill px-3 py-1 transition-colors',
                    mode === 'visual'
                        ? 'bg-accent-blue/15 text-ink'
                        : 'text-ink-muted hover:text-ink',
                ]"
                @click="mode = 'visual'"
            >
                <Eye class="size-3" />
                {{ t('documents.workbench.mode.visual') }}
            </button>
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-pill px-3 py-1 transition-colors',
                    mode === 'code'
                        ? 'bg-accent-blue/15 text-ink'
                        : 'text-ink-muted hover:text-ink',
                ]"
                @click="mode = 'code'"
            >
                <Code class="size-3" />
                {{ t('documents.workbench.mode.code') }}
            </button>
        </div>

        <!-- Body region — fills remaining vertical space -->
        <div class="flex min-h-0 flex-1 overflow-hidden rounded-sp-sm border border-soft bg-navy">
            <!-- AI mode: chat column + iframe preview -->
            <template v-if="mode === 'ai'">
                <aside class="flex w-[360px] shrink-0 flex-col border-r border-soft">
                    <div
                        ref="chatScroll"
                        class="flex-1 space-y-3 overflow-y-auto p-4"
                    >
                        <div
                            v-for="(msg, idx) in messages"
                            :key="idx"
                            class="flex gap-2.5"
                            :class="msg.role === 'user' ? 'flex-row-reverse' : ''"
                        >
                            <div
                                class="flex size-7 shrink-0 items-center justify-center rounded-pill"
                                :class="
                                    msg.role === 'user'
                                        ? 'bg-accent-blue/20 text-accent-blue'
                                        : 'bg-white/5 text-ink-muted'
                                "
                            >
                                <User v-if="msg.role === 'user'" class="size-3.5" />
                                <Bot v-else class="size-3.5" />
                            </div>
                            <div
                                class="max-w-[240px] rounded-sp-sm px-3 py-2 text-[13px] leading-snug"
                                :class="
                                    msg.role === 'user'
                                        ? 'bg-accent-blue/15 text-ink'
                                        : 'bg-white/[0.04] text-ink'
                                "
                            >
                                {{ msg.content }}
                            </div>
                        </div>

                        <div
                            v-if="chatPending"
                            class="flex items-center gap-2 text-xs text-ink-muted"
                        >
                            <LoaderCircle class="size-3.5 animate-spin" />
                            {{ t('documents.workbench.ai.working') }}
                        </div>

                        <p
                            v-if="chatError"
                            class="rounded-xs border border-sp-danger/30 bg-sp-danger/5 px-3 py-2 text-xs text-sp-danger"
                        >
                            {{ chatError }}
                        </p>
                    </div>

                    <!-- Composer -->
                    <form
                        class="flex flex-col gap-2 border-t border-soft p-3"
                        @submit.prevent="sendMessage"
                    >
                        <Select
                            v-if="availableChatModels.length > 0"
                            v-model="aiModelId"
                            :disabled="chatPending"
                        >
                            <SelectTrigger class="h-8 w-full text-[11px]">
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
                        <div class="flex items-end gap-2">
                            <textarea
                                v-model="chatInput"
                                :placeholder="t('documents.workbench.ai.composer_placeholder')"
                                rows="2"
                                class="flex-1 resize-none rounded-xs border border-medium bg-white/5 p-2 text-[13px] text-ink placeholder:text-ink-subtle focus-visible:border-accent-blue focus-visible:ring-3 focus-visible:ring-accent-blue/25 focus-visible:outline-none"
                                :disabled="chatPending"
                                @keydown.enter.exact.prevent="sendMessage"
                            />
                            <button
                                type="submit"
                                :disabled="!chatInput.trim() || chatPending"
                                class="flex size-9 shrink-0 items-center justify-center rounded-pill bg-accent-blue text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                                :aria-label="t('documents.workbench.ai.send')"
                            >
                                <Send class="size-4" />
                            </button>
                        </div>
                    </form>
                </aside>

                <div class="flex-1 bg-white">
                    <iframe
                        :srcdoc="body"
                        sandbox="allow-scripts"
                        class="h-full w-full border-0"
                    />
                </div>
            </template>

            <!-- Visual — true WYSIWYG inside an iframe with the artifact's own CSS -->
            <template v-else-if="mode === 'visual'">
                <div class="min-h-0 flex-1 p-4">
                    <ArtifactVisualEditor v-model="body" />
                </div>
            </template>

            <!-- Code (CodeMirror) -->
            <template v-else>
                <div class="flex-1 p-4">
                    <HtmlCodeEditor v-model="body" class="h-full" />
                </div>
            </template>
        </div>
    </div>
</template>

