<script setup lang="ts">
import Topbar from '@/components/app-v2/Topbar.vue';
import ArtifactPanel from '@/components/chat/ArtifactPanel.vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import ChatEmptyState from '@/components/chat/ChatEmptyState.vue';
import ChatSidebar from '@/components/chat/ChatSidebar.vue';
import ChatThread from '@/components/chat/ChatThread.vue';
import echo from '@/echo';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { type Artifact, parseArtifacts } from '@/lib/artifacts';
import type {
    ActiveChatDto,
    ChatAgentOption,
    ChatListItem,
    ChatMessageDto,
    ChatModelOption,
    ChatProjectDto,
    ChatToolOption,
    ConsultationDto,
    KnowledgeBaseOption,
} from '@/types/chatModule';
import { Head, router } from '@inertiajs/vue3';
import { PanelLeftClose, PanelLeftOpen, Sparkles } from '@lucide/vue';
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    chats: ChatListItem[];
    projects: ChatProjectDto[];
    models: ChatModelOption[];
    defaultModel: string | null;
    activeChat: ActiveChatDto | null;
    knowledgeBases: KnowledgeBaseOption[];
    tools: ChatToolOption[];
    agents: ChatAgentOption[];
}>();

// The picker holds either a model id or `agent:{id}`; an agent selection
// takes precedence over the stored model.
function selectionFor(chat: ActiveChatDto | null): string | null {
    if (chat?.agent_id) return `agent:${chat.agent_id}`;
    return chat?.model ?? null;
}

const messages = ref<ChatMessageDto[]>(props.activeChat?.messages ?? []);
// Local copy of the active chat title so backend title updates (the first-turn
// title and the 6-message regeneration), broadcast on ChatStreamComplete,
// reflect in the header without a full activeChat reload.
const activeTitle = ref<string | null>(props.activeChat?.title ?? null);
const currentModel = ref<string | null>(
    selectionFor(props.activeChat) ??
        props.defaultModel ??
        props.models[0]?.value ??
        null,
);
const selectedToolIds = ref<string[]>(props.activeChat?.tool_ids ?? []);
const toolActivity = ref<Record<string, string>>({});
// Live agent-consultation cards per streaming message, keyed by consultation id.
const consultations = ref<Record<string, ConsultationDto[]>>({});
const composer = ref<InstanceType<typeof ChatComposer> | null>(null);
const stopped = ref<Set<string>>(new Set());

// Multi-agent (@mention) synthesis state.
const synthesisStatus = ref<ActiveChatDto['synthesis_status']>(
    props.activeChat?.synthesis_status ?? null,
);
const actionBusy = ref(false);

// ----- Inner chat sidebar (collapsible) -----
const SIDEBAR_KEY = 'chat:sidebar-open';
// Default closed for a focused landing; only an explicit stored `true`
// (the user opened it before) keeps it open. Their choice persists.
const chatSidebarOpen = ref(
    typeof window !== 'undefined' &&
        window.localStorage.getItem(SIDEBAR_KEY) === 'true',
);
watch(chatSidebarOpen, (open) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SIDEBAR_KEY, String(open));
    }
});

const activeId = computed(() => props.activeChat?.id ?? null);
const isMultiAgent = computed(() => props.activeChat?.mode === 'multi_agent');
// Offer a manual re-synthesis once agents have spoken but no proposal stands.
const canSynthesize = computed(
    () =>
        isMultiAgent.value &&
        (synthesisStatus.value === null ||
            synthesisStatus.value === 'dismissed'),
);
const isStreaming = computed(() =>
    messages.value.some(
        (m) => m.status === 'pending' || m.status === 'streaming',
    ),
);

// ----- Artifacts (side panel) -----
const activeArtifactId = ref<string | null>(null);
const seenArtifacts = ref<Set<string>>(new Set());

const allArtifacts = computed<Artifact[]>(() =>
    messages.value
        .filter((m) => m.role === 'assistant' && m.content)
        .flatMap((m) => parseArtifacts(m.content, m.id).artifacts),
);
const activeArtifact = computed<Artifact | null>(
    () =>
        allArtifacts.value.find((a) => a.id === activeArtifactId.value) ?? null,
);

function openArtifact(a: Artifact) {
    activeArtifactId.value = a.id;
}
function closeArtifact() {
    activeArtifactId.value = null;
}

// Auto-open newly produced artifacts (not pre-existing ones on chat load).
watch(
    allArtifacts,
    (arts) => {
        for (const a of arts) {
            if (!seenArtifacts.value.has(a.id)) {
                seenArtifacts.value.add(a.id);
                activeArtifactId.value = a.id;
            }
        }
    },
    { deep: true },
);

let tempCounter = 0;
const tempId = (p: string) => `temp-${p}-${++tempCounter}`;

function upsert(message: ChatMessageDto) {
    const idx = messages.value.findIndex((m) => m.id === message.id);
    if (idx === -1) messages.value = [...messages.value, message];
    else
        messages.value = messages.value.map((m) =>
            m.id === message.id ? { ...m, ...message } : m,
        );
}

// Flip a proposal card's per-message lifecycle status in place so it locks /
// hides without waiting for a reload (the ActionCard reads action_payload.status).
function markProposalStatus(
    proposalId: string,
    status: 'executed' | 'dismissed',
) {
    messages.value = messages.value.map((m) =>
        m.id === proposalId && m.action_payload
            ? { ...m, action_payload: { ...m.action_payload, status } }
            : m,
    );
}

// ----- Echo streaming -----
type ChannelHandle = ReturnType<typeof echo.private>;
let channel: ChannelHandle | null = null;
let subscribedId: string | null = null;

function subscribe(id: string) {
    unsubscribe();
    subscribedId = id;
    channel = echo.private(`chat.conversation.${id}`);

    channel.listen(
        '.ChatStreamChunk',
        (data: { message_id: string; delta: string }) => {
            if (stopped.value.has(data.message_id)) return;
            const existing = messages.value.find(
                (m) => m.id === data.message_id,
            );
            if (existing) {
                messages.value = messages.value.map((m) =>
                    m.id === data.message_id
                        ? {
                              ...m,
                              content: (m.content ?? '') + data.delta,
                              status: 'streaming',
                          }
                        : m,
                );
            } else {
                // Chunk arrived before the POST response (or after navigating into a
                // chat mid-stream) — materialize the assistant message.
                upsert({
                    id: data.message_id,
                    role: 'assistant',
                    content: data.delta,
                    model: currentModel.value,
                    status: 'streaming',
                    error: null,
                    created_at: null,
                    attachments: [],
                });
            }
        },
    );

    channel.listen(
        '.ChatToolCall',
        (data: { message_id: string; tool_name: string }) => {
            toolActivity.value = {
                ...toolActivity.value,
                [data.message_id]: data.tool_name,
            };
        },
    );

    channel.listen(
        '.ChatAgentConsultation',
        (data: {
            message_id: string;
            phase: 'start' | 'delta' | 'result';
            consultation_id: string;
            agent_id: string;
            agent_name: string;
            question: string;
            visible: boolean;
            answer: string | null;
        }) => {
            // Update in place (preserving order): `start` opens the card, each
            // `delta` appends the consulted agent's next chunk live, `result`
            // replaces it with the final answer and clears the pending state.
            const list = (consultations.value[data.message_id] ?? []).map(
                (c) => ({ ...c }),
            );
            const idx = list.findIndex((c) => c.id === data.consultation_id);
            const prevAnswer = idx >= 0 ? list[idx].answer : null;
            const entry = {
                id: data.consultation_id,
                agent_id: data.agent_id,
                agent_name: data.agent_name,
                question: data.question,
                answer:
                    data.phase === 'delta'
                        ? (prevAnswer ?? '') + (data.answer ?? '')
                        : data.answer,
                visible: data.visible,
                pending: data.phase !== 'result',
            };
            if (idx >= 0) {
                list[idx] = entry;
            } else {
                list.push(entry);
            }
            consultations.value = {
                ...consultations.value,
                [data.message_id]: list,
            };
        },
    );

    channel.listen(
        '.ChatStreamComplete',
        (payload: {
            message: ChatMessageDto;
            chat_id?: string;
            title?: string | null;
        }) => {
            upsert({ ...payload.message, attachments: [] });
            delete toolActivity.value[payload.message.id];
            toolActivity.value = { ...toolActivity.value };
            // The completed message now carries consultation_context; drop the
            // live copy so the persisted one renders.
            delete consultations.value[payload.message.id];
            consultations.value = { ...consultations.value };
            if (payload.title && payload.chat_id === activeId.value) {
                activeTitle.value = payload.title;
            }
            router.reload({ only: ['chats'] });
        },
    );

    channel.listen(
        '.ChatStreamError',
        (data: { message_id: string; error: string }) => {
            messages.value = messages.value.map((m) =>
                m.id === data.message_id
                    ? { ...m, status: 'error', error: data.error }
                    : m,
            );
            delete toolActivity.value[data.message_id];
            toolActivity.value = { ...toolActivity.value };
        },
    );

    // ----- Multi-agent (@mention) events -----
    channel.listen(
        '.ChatAgentStarted',
        (payload: { message: ChatMessageDto }) => {
            upsert(payload.message);
        },
    );

    channel.listen(
        '.ChatActionProposalReady',
        (payload: {
            message: ChatMessageDto;
            synthesis_status: ActiveChatDto['synthesis_status'];
        }) => {
            upsert({ ...payload.message, attachments: [] });
            // Single-turn proposals carry no chat-level status (empty string);
            // only the multi-agent flow advances it.
            if (payload.synthesis_status)
                synthesisStatus.value = payload.synthesis_status;
            router.reload({ only: ['chats'] });
        },
    );

    channel.listen(
        '.ChatActionExecuted',
        (payload: {
            message: ChatMessageDto;
            synthesis_status: ActiveChatDto['synthesis_status'];
        }) => {
            actionBusy.value = false;
            if (payload.synthesis_status)
                synthesisStatus.value = payload.synthesis_status;
            if (payload.message.message_type === 'action_result') {
                // An execution appends a result message; lock its source proposal.
                upsert({ ...payload.message, attachments: [] });
                const resultPayload = payload.message.action_payload as
                    | (Record<string, unknown> & { proposal_id?: string })
                    | null
                    | undefined;
                if (resultPayload?.proposal_id)
                    markProposalStatus(resultPayload.proposal_id, 'executed');
            } else if (payload.message.message_type === 'action_proposal') {
                // A dismissal broadcasts the proposal itself (status already flipped).
                upsert({ ...payload.message, attachments: [] });
            }
        },
    );
}

function unsubscribe() {
    if (channel && subscribedId) {
        channel.stopListening('.ChatStreamChunk');
        channel.stopListening('.ChatToolCall');
        channel.stopListening('.ChatAgentConsultation');
        channel.stopListening('.ChatStreamComplete');
        channel.stopListening('.ChatStreamError');
        channel.stopListening('.ChatAgentStarted');
        channel.stopListening('.ChatActionProposalReady');
        channel.stopListening('.ChatActionExecuted');
        echo.leave(`chat.conversation.${subscribedId}`);
        channel = null;
        subscribedId = null;
    }
}

// React to switching chats (Inertia partial reload swaps activeChat).
watch(
    () => props.activeChat?.id,
    (id) => {
        messages.value = props.activeChat?.messages ?? [];
        activeTitle.value = props.activeChat?.title ?? null;
        currentModel.value =
            selectionFor(props.activeChat) ?? currentModel.value;
        selectedToolIds.value = props.activeChat?.tool_ids ?? [];
        synthesisStatus.value = props.activeChat?.synthesis_status ?? null;
        actionBusy.value = false;
        toolActivity.value = {};
        consultations.value = {};
        stopped.value = new Set();
        // Treat artifacts already in the loaded conversation as "seen" so we
        // don't auto-open an old artifact every time the chat is opened.
        seenArtifacts.value = new Set(allArtifacts.value.map((a) => a.id));
        activeArtifactId.value = null;
        if (id) subscribe(id);
        else unsubscribe();
    },
    { immediate: true },
);

onBeforeUnmount(unsubscribe);

// ----- Sending -----
async function send(payload: {
    content: string;
    attachmentIds: string[];
    webSearch?: boolean;
    toolIds?: string[];
    mentionedAgentIds?: string[];
}) {
    const {
        content,
        attachmentIds,
        webSearch = false,
        toolIds = selectedToolIds.value,
        mentionedAgentIds = [],
    } = payload;

    if (!activeId.value) {
        // No chat yet — create it with the first turn in one navigation.
        router.post('/chat', {
            content,
            model: currentModel.value,
            web_search: webSearch,
            tool_ids: toolIds,
            mentioned_agent_ids: mentionedAgentIds,
        });
        return;
    }

    // Optimistic user message.
    const userMsg: ChatMessageDto = {
        id: tempId('user'),
        role: 'user',
        content: content || null,
        model: currentModel.value,
        status: 'complete',
        error: null,
        created_at: new Date().toISOString(),
        attachments: [],
    };
    messages.value = [...messages.value, userMsg];

    try {
        const { data } = await axios.post(`/chat/${activeId.value}/messages`, {
            content,
            model: currentModel.value,
            attachment_ids: attachmentIds,
            web_search: webSearch,
            tool_ids: toolIds,
            mentioned_agent_ids: mentionedAgentIds,
        });
        // Replace optimistic user msg with the real one.
        messages.value = messages.value.map((m) =>
            m.id === userMsg.id ? data.user_message : m,
        );
        if (data.mode === 'multi_agent') {
            // Agent bubbles + the action proposal arrive over Reverb.
            synthesisStatus.value = 'pending';
            if (data.system_notice) upsert(data.system_notice);
        } else {
            upsert(data.placeholder);
        }
    } catch {
        messages.value = messages.value.map((m) =>
            m.id === userMsg.id
                ? { ...m, status: 'error', error: t('chat.send_failed') }
                : m,
        );
    }
}

async function executeAction(message: ChatMessageDto) {
    if (!activeId.value) return;
    actionBusy.value = true;
    try {
        const { data } = await axios.post(
            `/chat/${activeId.value}/actions/${message.id}/execute`,
        );
        if (data.synthesis_status)
            synthesisStatus.value = data.synthesis_status;
        markProposalStatus(message.id, 'executed');
        if (data.message) upsert({ ...data.message, attachments: [] });
    } catch {
        // leave the card actionable on failure
    } finally {
        actionBusy.value = false;
    }
}

async function dismissAction(message: ChatMessageDto) {
    if (!activeId.value) return;
    try {
        const { data } = await axios.delete(
            `/chat/${activeId.value}/actions/${message.id}`,
        );
        if (data?.synthesis_status)
            synthesisStatus.value = data.synthesis_status;
        markProposalStatus(message.id, 'dismissed');
    } catch {
        // no-op
    }
}

function synthesize() {
    if (!activeId.value) return;
    synthesisStatus.value = 'pending';
    axios.post(`/chat/${activeId.value}/synthesize`).catch(() => {});
}

function onRequiresChat() {
    // User tried to attach before a chat exists — create an empty chat first.
    router.post('/chat', { model: currentModel.value });
}

function pickSuggestion(prompt: string) {
    send({ content: prompt, attachmentIds: [], webSearch: false });
}

function stop() {
    const streaming = messages.value.find(
        (m) => m.status === 'pending' || m.status === 'streaming',
    );
    if (!streaming) return;
    // Tell the worker to cancel (it finalizes the partial reply), then reflect
    // it immediately in the UI.
    if (activeId.value && !streaming.id.startsWith('temp-')) {
        axios
            .post(`/chat/${activeId.value}/stop`, { message_id: streaming.id })
            .catch(() => {});
    }
    stopped.value.add(streaming.id);
    messages.value = messages.value.map((m) =>
        m.id === streaming.id ? { ...m, status: 'complete' } : m,
    );
}

function retry() {
    const lastUser = [...messages.value]
        .reverse()
        .find((m) => m.role === 'user');
    if (!lastUser?.content) return;
    // Drop the trailing errored assistant message before resending.
    messages.value = messages.value.filter((m) => m.status !== 'error');
    send({ content: lastUser.content, attachmentIds: [], webSearch: false });
}
</script>

<template>
    <Head :title="activeTitle || t('app_v2.nav.chat')" />

    <AppLayoutV2
        v-slot="{ openPalette, toggleSidebar, sidebarCollapsed }"
        :title="activeTitle || t('app_v2.nav.chat')"
        bg="flat"
        :full-bleed="true"
        hide-topbar
    >
        <div class="flex min-h-0 flex-1">
            <div
                :class="[
                    'hidden shrink-0 overflow-hidden transition-[width] duration-200 ease-in-out md:block',
                    chatSidebarOpen ? 'w-72' : 'w-0',
                ]"
            >
                <ChatSidebar
                    :chats="chats"
                    :projects="projects"
                    :knowledge-bases="knowledgeBases"
                    :active-id="activeId"
                />
            </div>

            <div class="flex min-h-0 flex-1 flex-col">
                <Topbar
                    :title="activeTitle || t('app_v2.nav.chat')"
                    :sidebar-collapsed="sidebarCollapsed"
                    @toggle-sidebar="toggleSidebar"
                    @open-palette="openPalette"
                >
                    <template #leading>
                        <button
                            type="button"
                            class="hidden size-9 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-surface hover:text-ink md:flex"
                            :aria-label="
                                chatSidebarOpen
                                    ? t('chat.hide_sidebar')
                                    : t('chat.show_sidebar')
                            "
                            @click="chatSidebarOpen = !chatSidebarOpen"
                        >
                            <PanelLeftClose
                                v-if="chatSidebarOpen"
                                class="size-4"
                            />
                            <PanelLeftOpen v-else class="size-4" />
                        </button>
                    </template>
                </Topbar>
                <template v-if="activeChat">
                    <ChatThread
                        :messages="messages"
                        :title="activeTitle"
                        :active-artifact-id="activeArtifactId"
                        :tool-activity="toolActivity"
                        :consultations="consultations"
                        :synthesis-status="synthesisStatus"
                        :action-busy="actionBusy"
                        :agents="agents"
                        @retry="retry"
                        @open-artifact="openArtifact"
                        @execute="executeAction"
                        @dismiss="dismissAction"
                    />
                    <div class="px-7 pb-4">
                        <div class="mx-auto w-full max-w-[820px]">
                            <div
                                v-if="canSynthesize"
                                class="mb-2 flex justify-center"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-medium bg-surface px-3 py-1.5 text-[13px] font-medium text-ink-muted transition-colors hover:border-strong hover:text-ink"
                                    @click="synthesize"
                                >
                                    <Sparkles
                                        class="size-3.5 text-accent-blue"
                                    />
                                    {{ t('chat.action.synthesize') }}
                                </button>
                            </div>
                            <ChatComposer
                                ref="composer"
                                :models="models"
                                :model="currentModel"
                                :busy="isStreaming"
                                :chat-id="activeChat.id"
                                :tools="tools"
                                :tool-ids="selectedToolIds"
                                :agents="agents"
                                @update:model="currentModel = $event"
                                @update:tool-ids="selectedToolIds = $event"
                                @submit="send"
                                @stop="stop"
                                @requires-chat="onRequiresChat"
                            />
                        </div>
                    </div>
                </template>

                <template v-else>
                    <ChatEmptyState @pick="pickSuggestion" />
                    <div class="px-4 pb-12">
                        <div class="mx-auto w-full max-w-2xl">
                            <ChatComposer
                                ref="composer"
                                :models="models"
                                :model="currentModel"
                                :busy="false"
                                :chat-id="null"
                                :autofocus="true"
                                :tools="tools"
                                :tool-ids="selectedToolIds"
                                :agents="agents"
                                @update:model="currentModel = $event"
                                @update:tool-ids="selectedToolIds = $event"
                                @submit="send"
                                @requires-chat="onRequiresChat"
                            />
                        </div>
                    </div>
                </template>
            </div>

            <!-- Artifact side panel -->
            <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="translate-x-full opacity-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-to-class="translate-x-full opacity-0"
            >
                <div
                    v-if="activeArtifact"
                    class="hidden w-full max-w-xl shrink-0 md:flex"
                >
                    <ArtifactPanel
                        :artifact="activeArtifact"
                        @close="closeArtifact"
                    />
                </div>
            </Transition>
        </div>
    </AppLayoutV2>
</template>
