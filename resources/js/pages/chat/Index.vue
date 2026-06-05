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
    KnowledgeBaseOption,
} from '@/types/chatModule';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
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
const currentModel = ref<string | null>(
    selectionFor(props.activeChat) ??
        props.defaultModel ??
        props.models[0]?.value ??
        null,
);
const selectedToolIds = ref<string[]>(props.activeChat?.tool_ids ?? []);
const toolActivity = ref<Record<string, string>>({});
const composer = ref<InstanceType<typeof ChatComposer> | null>(null);
const stopped = ref<Set<string>>(new Set());

// ----- Inner chat sidebar (collapsible) -----
const SIDEBAR_KEY = 'chat:sidebar-open';
const chatSidebarOpen = ref(
    typeof window === 'undefined' ||
        window.localStorage.getItem(SIDEBAR_KEY) !== 'false',
);
watch(chatSidebarOpen, (open) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SIDEBAR_KEY, String(open));
    }
});

const activeId = computed(() => props.activeChat?.id ?? null);
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
        '.ChatStreamComplete',
        (payload: { message: ChatMessageDto }) => {
            upsert({ ...payload.message, attachments: [] });
            delete toolActivity.value[payload.message.id];
            toolActivity.value = { ...toolActivity.value };
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
}

function unsubscribe() {
    if (channel && subscribedId) {
        channel.stopListening('.ChatStreamChunk');
        channel.stopListening('.ChatStreamComplete');
        channel.stopListening('.ChatStreamError');
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
        currentModel.value =
            selectionFor(props.activeChat) ?? currentModel.value;
        selectedToolIds.value = props.activeChat?.tool_ids ?? [];
        toolActivity.value = {};
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
}) {
    const {
        content,
        attachmentIds,
        webSearch = false,
        toolIds = selectedToolIds.value,
    } = payload;

    if (!activeId.value) {
        // No chat yet — create it with the first turn in one navigation.
        router.post('/chat', {
            content,
            model: currentModel.value,
            web_search: webSearch,
            tool_ids: toolIds,
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
        });
        // Replace optimistic user msg with the real one + add the placeholder.
        messages.value = messages.value.map((m) =>
            m.id === userMsg.id ? data.user_message : m,
        );
        upsert(data.placeholder);
    } catch {
        messages.value = messages.value.map((m) =>
            m.id === userMsg.id
                ? { ...m, status: 'error', error: t('chat.send_failed') }
                : m,
        );
    }
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
    <Head :title="activeChat?.title || t('app_v2.nav.chat')" />

    <AppLayoutV2
        v-slot="{ openPalette, toggleSidebar, sidebarCollapsed }"
        :title="activeChat?.title || t('app_v2.nav.chat')"
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
                    :title="activeChat?.title || t('app_v2.nav.chat')"
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
                            <PanelLeftClose v-if="chatSidebarOpen" class="size-4" />
                            <PanelLeftOpen v-else class="size-4" />
                        </button>
                    </template>
                </Topbar>
                <template v-if="activeChat">
                    <ChatThread
                        :messages="messages"
                        :title="activeChat.title"
                        :active-artifact-id="activeArtifactId"
                        :tool-activity="toolActivity"
                        @retry="retry"
                        @open-artifact="openArtifact"
                    />
                    <div class="px-7 pb-4">
                        <div class="mx-auto w-full max-w-[820px]">
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
