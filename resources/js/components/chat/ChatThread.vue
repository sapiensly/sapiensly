<script setup lang="ts">
import ActionCard from '@/components/chat/ActionCard.vue';
import AgentConsultationCard from '@/components/chat/AgentConsultationCard.vue';
import AgentMessageBubble from '@/components/chat/AgentMessageBubble.vue';
import ArtifactCard from '@/components/chat/ArtifactCard.vue';
import QuestionCard from '@/components/chat/QuestionCard.vue';
import TeamTurn from '@/components/chat/TeamTurn.vue';
import ToolActivityChips from '@/components/chat/ToolActivityChips.vue';
import UserMessageBubble from '@/components/chat/UserMessageBubble.vue';
import type { Artifact } from '@/lib/artifacts';
import { normalizeChatMarkdown } from '@/lib/markdown';
import { buildMessageContent } from '@/lib/messageSegments';
import type {
    ChatAgentRef,
    ChatMessageDto,
    ChatSynthesisStatus,
    ConsultationDto,
    ToolActivityDto,
} from '@/types/chatModule';
import { Check, Copy, RotateCw, Sparkles } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    messages: ChatMessageDto[];
    title?: string | null;
    activeArtifactId?: string | null;
    toolActivity?: Record<string, ToolActivityDto[]>;
    consultations?: Record<string, ConsultationDto[]>;
    synthesisStatus?: ChatSynthesisStatus;
    // Id of the proposal/question currently executing — only that card spins.
    actionBusyId?: string | null;
    agents?: ChatAgentRef[];
}>();

// The consultations to render for a message: the live (streaming) ones while
// they arrive, falling back to the persisted consultation_context on reload.
function consultationsFor(m: ChatMessageDto): ConsultationDto[] {
    return props.consultations?.[m.id] ?? m.consultation_context ?? [];
}

interface TurnItem {
    m: ChatMessageDto;
    index: number;
}

interface Turn {
    key: string;
    isTeam: boolean;
    items: TurnItem[];
    user: ChatMessageDto | null;
    deliberation: ChatMessageDto[];
    close: ChatMessageDto | null;
    result: ChatMessageDto | null;
    isLastTurn: boolean;
}

// Group the flat message list into turns (each user message opens one). A turn
// is a "team turn" when it carries agent-authored messages or an action
// proposal — those render answer-first via TeamTurn. Everything else (regular
// and single-agent chats) keeps the flat, chronological rendering.
const turns = computed<Turn[]>(() => {
    const groups: TurnItem[][] = [];
    props.messages.forEach((m, index) => {
        if (m.role === 'user' || groups.length === 0) {
            groups.push([]);
        }
        groups[groups.length - 1].push({ m, index });
    });

    return groups.map((items, gi) => {
        const msgs = items.map((it) => it.m);
        const user = msgs.find((m) => m.role === 'user') ?? null;
        // A "team turn" is a multi-agent deliberation — it must carry
        // agent-authored messages. A lone action_proposal in a plain chat (a
        // propose_build card) is NOT a team turn: it renders flat so the
        // assistant's own explanation shows inline instead of being buried in
        // an empty "how the team decided (0)" toggle.
        const isTeam = msgs.some((m) => m.agent_id);
        const proposal =
            msgs.find((m) => m.message_type === 'action_proposal') ?? null;
        const result =
            msgs.find((m) => m.message_type === 'action_result') ?? null;

        // The close is the proposal, or — when synthesis was dismissed — the
        // trailing "no recommendation" system note.
        let close: ChatMessageDto | null = proposal;
        if (isTeam && !proposal) {
            const systems = msgs.filter((m) => m.role === 'system');
            close = systems.length ? systems[systems.length - 1] : null;
        }
        const deliberation = isTeam
            ? msgs.filter((m) => m !== user && m !== close && m !== result)
            : [];

        return {
            key: user?.id ?? msgs[0]?.id ?? `turn-${gi}`,
            isTeam,
            items,
            user,
            deliberation,
            close,
            result,
            isLastTurn: gi === groups.length - 1,
        };
    });
});

const emit = defineEmits<{
    retry: [];
    openArtifact: [artifact: Artifact];
    execute: [message: ChatMessageDto];
    dismiss: [message: ChatMessageDto];
    answerQuestion: [message: ChatMessageDto, text: string];
}>();

function isAgentMessage(m: ChatMessageDto): boolean {
    return !!m.agent_id && (m.message_type ?? 'text') === 'text';
}

// Parse each plain-assistant message once per render into its ordered render
// list (text + artifacts + consultation cards spliced in at their marker).
const messageContent = computed(() => {
    const map = new Map<string, ReturnType<typeof buildMessageContent>>();
    for (const m of props.messages) {
        if (m.role !== 'assistant' || isAgentMessage(m)) continue;
        const settled = m.status === 'complete' || m.status === 'error';
        map.set(
            m.id,
            buildMessageContent(m.content, m.id, settled, consultationsFor(m)),
        );
    }
    return map;
});

function contentFor(m: ChatMessageDto) {
    return messageContent.value.get(m.id) ?? { leading: [], segments: [] };
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
                <template v-for="turn in turns" :key="turn.key">
                    <!-- Multi-agent turn: answer-first, deliberation collapsed. -->
                    <TeamTurn
                        v-if="turn.isTeam"
                        :user="turn.user"
                        :deliberation="turn.deliberation"
                        :close="turn.close"
                        :result="turn.result"
                        :synthesis-status="synthesisStatus"
                        :action-busy-id="actionBusyId"
                        :agents="agents"
                        :consultations="consultations"
                        :tool-activity="toolActivity"
                        :active-artifact-id="activeArtifactId"
                        :is-last-turn="turn.isLastTurn"
                        @execute="emit('execute', $event)"
                        @dismiss="emit('dismiss', $event)"
                        @open-artifact="emit('openArtifact', $event)"
                    />

                    <!-- Regular / single-agent turn: flat, chronological. -->
                    <div v-else class="space-y-7">
                        <div v-for="{ m, index } in turn.items" :key="m.id">
                            <!-- User turn -->
                            <UserMessageBubble
                                v-if="m.role === 'user'"
                                :message="m"
                                :agents="agents"
                            />

                            <!-- Action proposal (e.g. a propose_build card) -->
                            <ActionCard
                                v-else-if="m.message_type === 'action_proposal'"
                                :message="m"
                                :status="synthesisStatus"
                                :busy="actionBusyId === m.id"
                                @execute="emit('execute', m)"
                                @dismiss="emit('dismiss', m)"
                                @open-artifact="emit('openArtifact', $event)"
                            />

                            <!-- Executed action result (e.g. "Done — saved …") -->
                            <div
                                v-else-if="m.message_type === 'action_result'"
                                class="rounded-2xl border border-sp-success/30 bg-sp-success/[0.08] p-3.5 text-sm whitespace-pre-wrap text-ink"
                            >
                                {{ m.content }}
                            </div>

                            <!-- Multiple-choice question (ask_user_question) -->
                            <QuestionCard
                                v-else-if="m.message_type === 'question'"
                                :message="m"
                                :busy="actionBusyId === m.id"
                                @answer="emit('answerQuestion', m, $event)"
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
                                :consultations="consultationsFor(m)"
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
                                            v-if="isLast(index)"
                                            type="button"
                                            class="mt-2.5 inline-flex items-center gap-1.5 rounded-lg border border-sp-danger/30 px-2.5 py-1 text-xs transition-colors hover:bg-sp-danger/10"
                                            @click="emit('retry')"
                                        >
                                            <RotateCw class="size-3.5" />
                                            {{ t('chat.retry') }}
                                        </button>
                                    </div>
                                    <template v-else>
                                        <ToolActivityChips
                                            :items="toolActivity?.[m.id] ?? []"
                                        />
                                        <!-- Legacy consultations with no inline marker. -->
                                        <AgentConsultationCard
                                            v-for="c in contentFor(m).leading"
                                            :key="c.id"
                                            :consultation="c"
                                        />
                                        <template
                                            v-for="(seg, si) in contentFor(m)
                                                .segments"
                                            :key="si"
                                        >
                                            <ArtifactCard
                                                v-if="seg.kind === 'artifact'"
                                                :artifact="seg.artifact"
                                                :active="
                                                    activeArtifactId ===
                                                    seg.artifact.id
                                                "
                                                @open="
                                                    emit('openArtifact', $event)
                                                "
                                            />
                                            <AgentConsultationCard
                                                v-else-if="
                                                    seg.kind === 'consult'
                                                "
                                                :consultation="seg.consultation"
                                            />
                                            <div
                                                v-else
                                                class="sp-chat-prose prose prose-sm max-w-none dark:prose-invert"
                                                v-html="
                                                    renderMarkdown(seg.text)
                                                "
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
                                            v-if="
                                                m.status === 'complete' &&
                                                m.content
                                            "
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
                </template>
            </div>
        </div>
    </div>
</template>
