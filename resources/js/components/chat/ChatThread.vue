<script setup lang="ts">
import ActionCard from '@/components/chat/ActionCard.vue';
import AgentMessageBubble from '@/components/chat/AgentMessageBubble.vue';
import ArtifactCard from '@/components/chat/ArtifactCard.vue';
import { type Artifact, parseArtifacts, type Segment } from '@/lib/artifacts';
import { normalizeChatMarkdown } from '@/lib/markdown';
import type { ChatMessageDto, ChatSynthesisStatus } from '@/types/chatModule';
import { Check, Copy, FileText, RotateCw, Sparkles, Wrench } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    messages: ChatMessageDto[];
    title?: string | null;
    activeArtifactId?: string | null;
    toolActivity?: Record<string, string>;
    synthesisStatus?: ChatSynthesisStatus;
    actionBusy?: boolean;
}>();

const emit = defineEmits<{
    retry: [];
    openArtifact: [artifact: Artifact];
    execute: [message: ChatMessageDto];
    dismiss: [message: ChatMessageDto];
}>();

function isAgentMessage(m: ChatMessageDto): boolean {
    return !!m.agent_id && (m.message_type ?? 'text') === 'text';
}

function segmentsFor(m: ChatMessageDto): Segment[] {
    const settled = m.status === 'complete' || m.status === 'error';
    return parseArtifacts(m.content, m.id, settled).segments;
}

function prettyToolName(name: string): string {
    return name.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const scroller = ref<HTMLElement | null>(null);
const stuckToBottom = ref(true);
const copiedId = ref<string | null>(null);

function renderMarkdown(content: string | null): string {
    if (!content) return '';
    const raw = marked.parse(normalizeChatMarkdown(content), {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}

function onScroll() {
    const el = scroller.value;
    if (!el) return;
    stuckToBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}

function scrollToBottom(behavior: ScrollBehavior = 'smooth') {
    nextTick(() => {
        const el = scroller.value;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior });
    });
}

async function copy(m: ChatMessageDto) {
    if (!m.content) return;
    try {
        await navigator.clipboard.writeText(m.content);
        copiedId.value = m.id;
        setTimeout(() => {
            if (copiedId.value === m.id) copiedId.value = null;
        }, 1500);
    } catch {
        /* clipboard unavailable */
    }
}

// The streaming text and message count both change as deltas arrive.
const streamSignature = computed(() =>
    props.messages
        .map((m) => `${m.id}:${m.content?.length ?? 0}:${m.status}`)
        .join('|'),
);

watch(streamSignature, () => {
    if (stuckToBottom.value) scrollToBottom('smooth');
});

onMounted(() => scrollToBottom('instant'));

function isLast(index: number): boolean {
    return index === props.messages.length - 1;
}
</script>

<template>
    <div
        ref="scroller"
        class="flex-1 overflow-y-auto"
        @scroll.passive="onScroll"
    >
        <div class="mx-auto w-full max-w-[820px] px-7 pt-9 pb-6">
            <div class="space-y-7">
                <div v-for="(m, i) in messages" :key="m.id">
                    <!-- User turn -->
                    <div v-if="m.role === 'user'" class="flex justify-end">
                        <div class="max-w-[75%]">
                            <div
                                v-if="m.attachments.length"
                                class="mb-1.5 flex flex-wrap justify-end gap-2"
                            >
                                <a
                                    v-for="a in m.attachments"
                                    :key="a.id"
                                    :href="a.url"
                                    target="_blank"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-medium bg-surface px-2 py-1 text-xs text-ink transition-colors hover:border-strong"
                                >
                                    <img
                                        v-if="a.mime.startsWith('image/')"
                                        :src="a.url"
                                        :alt="a.original_name"
                                        class="size-9 rounded-lg object-cover"
                                    />
                                    <FileText
                                        v-else
                                        class="size-3.5 text-ink-subtle"
                                    />
                                    <span class="max-w-[160px] truncate">{{
                                        a.original_name
                                    }}</span>
                                </a>
                            </div>
                            <div
                                class="rounded-[1.4rem] bg-accent-blue px-5 py-3 text-[15px] leading-relaxed font-medium text-white shadow-[0_4px_14px_rgba(26,126,240,0.28)]"
                            >
                                <p class="break-words whitespace-pre-wrap">
                                    {{ m.content }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Action proposal (synthesized close) -->
                    <ActionCard
                        v-else-if="m.message_type === 'action_proposal'"
                        :message="m"
                        :status="synthesisStatus"
                        :busy="actionBusy"
                        @execute="emit('execute', m)"
                        @dismiss="emit('dismiss', m)"
                    />

                    <!-- System note (e.g. no-recommendation / cap notice) -->
                    <div
                        v-else-if="m.role === 'system'"
                        class="flex justify-center"
                    >
                        <p
                            class="rounded-full bg-white/5 px-3.5 py-1.5 text-center text-[12px] text-ink-subtle"
                        >
                            {{ m.content }}
                        </p>
                    </div>

                    <!-- Mentioned-agent turn -->
                    <AgentMessageBubble
                        v-else-if="isAgentMessage(m)"
                        :message="m"
                        :tool-activity="
                            toolActivity ? toolActivity[m.id] : null
                        "
                        :active-artifact-id="activeArtifactId"
                        @open-artifact="emit('openArtifact', $event)"
                    />

                    <!-- Assistant turn -->
                    <div v-else class="group flex gap-4">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-accent-blue/12 text-accent-blue"
                        >
                            <Sparkles class="size-[18px]" />
                        </div>
                        <div class="min-w-0 flex-1 pt-1">
                            <div
                                v-if="m.status === 'error'"
                                class="rounded-2xl border border-sp-danger/30 bg-sp-danger/10 p-3.5 text-sm text-sp-danger"
                            >
                                <p>
                                    {{
                                        m.error ||
                                        m.content ||
                                        t('chat.error_generic')
                                    }}
                                </p>
                                <button
                                    v-if="isLast(i)"
                                    type="button"
                                    class="mt-2.5 inline-flex items-center gap-1.5 rounded-lg border border-sp-danger/30 px-2.5 py-1 text-xs transition-colors hover:bg-sp-danger/10"
                                    @click="emit('retry')"
                                >
                                    <RotateCw class="size-3.5" />
                                    {{ t('chat.retry') }}
                                </button>
                            </div>
                            <template v-else>
                                <div
                                    v-if="toolActivity && toolActivity[m.id]"
                                    class="mb-2 inline-flex items-center gap-1.5 rounded-full border border-accent-blue/30 bg-accent-blue/10 px-2.5 py-1 text-xs text-accent-blue"
                                >
                                    <Wrench class="size-3 animate-pulse" />
                                    {{
                                        t('chat.tools.using', {
                                            tool: prettyToolName(
                                                toolActivity[m.id],
                                            ),
                                        })
                                    }}
                                </div>
                                <template
                                    v-for="(seg, si) in segmentsFor(m)"
                                    :key="si"
                                >
                                    <ArtifactCard
                                        v-if="seg.kind === 'artifact'"
                                        :artifact="seg.artifact"
                                        :active="
                                            activeArtifactId === seg.artifact.id
                                        "
                                        @open="emit('openArtifact', $event)"
                                    />
                                    <div
                                        v-else
                                        class="sp-chat-prose prose prose-sm max-w-none dark:prose-invert"
                                        v-html="renderMarkdown(seg.text)"
                                    />
                                </template>
                                <span
                                    v-if="
                                        m.status === 'pending' ||
                                        m.status === 'streaming'
                                    "
                                    class="inline-block h-[18px] w-[3px] translate-y-1 animate-pulse rounded-full bg-accent-blue"
                                    :class="{ 'ml-0.5': m.content }"
                                />

                                <!-- Hover toolbar -->
                                <div
                                    v-if="m.status === 'complete' && m.content"
                                    class="mt-1.5 flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100"
                                >
                                    <button
                                        type="button"
                                        :title="t('chat.copy')"
                                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-[11px] text-ink-subtle transition-colors hover:bg-white/10 hover:text-ink"
                                        @click="copy(m)"
                                    >
                                        <Check
                                            v-if="copiedId === m.id"
                                            class="size-3.5 text-sp-success"
                                        />
                                        <Copy v-else class="size-3.5" />
                                        {{
                                            copiedId === m.id
                                                ? t('chat.copied')
                                                : t('chat.copy')
                                        }}
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
