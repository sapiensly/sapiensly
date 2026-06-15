<script setup lang="ts">
import echo from '@/echo';
import axios from 'axios';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import type { RuntimeTheme } from '@/runtime/types/manifest';

/**
 * Builder power #3, read slice (1c): the floating chat panel an end-user of a
 * built app uses to talk to its embedded agent. It starts a conversation, sends
 * messages to /r/{slug}/agent/messages, and streams the reply over the private
 * Reverb channel runtime.agent.conversation.{id} — mirroring the Builder chat.
 * Read-only for now: the agent answers over the app's data; it cannot mutate.
 */
const props = defineProps<{
    appSlug: string;
    agentName?: string;
    theme?: RuntimeTheme;
}>();

interface ActionPayload {
    status: 'pending' | 'executed' | 'dismissed';
    previews?: string[];
    actions?: unknown[];
}

interface ChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string | null;
    status: string;
    message_type?: string | null;
    action_payload?: ActionPayload | null;
    created_at?: string | null;
}

const open = ref(false);
const conversationId = ref<string | null>(null);
const messages = ref<ChatMessage[]>([]);
const input = ref('');
const sending = ref(false);
const starting = ref(false);
const errorText = ref('');
const listEl = ref<HTMLElement | null>(null);

const dark = computed(() => props.theme === 'dark');
const name = computed(() => props.agentName || 'Assistant');

// The assistant is "thinking" while a placeholder exists with no content yet.
const awaitingFirstToken = computed(() =>
    messages.value.some((m) => m.role === 'assistant' && m.status === 'streaming' && !m.content),
);

let channel: ReturnType<typeof echo.private> | null = null;

function subscribe(id: string): void {
    unsubscribe();
    channel = echo.private(`runtime.agent.conversation.${id}`);

    channel.listen('.RuntimeAgentStreamChunk', (data: { message_id: string; delta: string }) => {
        messages.value = messages.value.map((m) =>
            m.id === data.message_id ? { ...m, content: (m.content ?? '') + data.delta } : m,
        );
        scrollToBottom();
    });

    channel.listen('.RuntimeAgentStreamComplete', (payload: { message: ChatMessage }) => {
        messages.value = messages.value.map((m) => (m.id === payload.message.id ? payload.message : m));
        scrollToBottom();
    });

    channel.listen('.RuntimeAgentStreamError', (data: { message_id: string; error: string }) => {
        messages.value = messages.value.map((m) =>
            m.id === data.message_id ? { ...m, content: data.error, status: 'error' } : m,
        );
    });
}

function unsubscribe(): void {
    if (channel && conversationId.value) {
        channel.stopListening('.RuntimeAgentStreamChunk');
        channel.stopListening('.RuntimeAgentStreamComplete');
        channel.stopListening('.RuntimeAgentStreamError');
        echo.leave(`runtime.agent.conversation.${conversationId.value}`);
    }
    channel = null;
}

async function ensureConversation(): Promise<void> {
    if (conversationId.value !== null || starting.value) {
        return;
    }
    starting.value = true;
    errorText.value = '';
    try {
        const { data } = await axios.post(`/r/${props.appSlug}/agent/conversations`, {}, { timeout: 15_000 });
        conversationId.value = data.conversation_id;
        subscribe(data.conversation_id);
    } catch {
        errorText.value = 'Could not start the assistant. Please try again.';
    } finally {
        starting.value = false;
    }
}

async function toggle(): Promise<void> {
    open.value = !open.value;
    if (open.value) {
        await ensureConversation();
        scrollToBottom();
    }
}

async function send(): Promise<void> {
    const text = input.value.trim();
    if (text === '' || sending.value) {
        return;
    }
    await ensureConversation();
    if (conversationId.value === null) {
        return;
    }

    sending.value = true;
    input.value = '';
    errorText.value = '';
    try {
        const { data } = await axios.post(
            `/r/${props.appSlug}/agent/messages`,
            { conversation_id: conversationId.value, message: text },
            { timeout: 15_000 },
        );
        messages.value = data.messages;
        scrollToBottom();
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        errorText.value = err.response?.data?.message ?? 'Could not send your message.';
    } finally {
        sending.value = false;
    }
}

const actingId = ref<string | null>(null);

function replaceMessage(updated: ChatMessage): void {
    messages.value = messages.value.map((m) => (m.id === updated.id ? updated : m));
}

async function resolveProposal(message: ChatMessage, verb: 'approve' | 'dismiss'): Promise<void> {
    if (actingId.value) {
        return;
    }
    actingId.value = message.id;
    errorText.value = '';
    try {
        const { data } = await axios.post(
            `/r/${props.appSlug}/agent/messages/${message.id}/${verb}`,
            {},
            { timeout: 30_000 },
        );
        replaceMessage(data.message);
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        errorText.value = err.response?.data?.message ?? `Could not ${verb} the change.`;
    } finally {
        actingId.value = null;
    }
}

function isPendingProposal(m: ChatMessage): boolean {
    return m.message_type === 'action_proposal' && m.action_payload?.status === 'pending';
}

function onComposerKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void send();
    }
}

function scrollToBottom(): void {
    void nextTick(() => {
        if (listEl.value) {
            listEl.value.scrollTop = listEl.value.scrollHeight;
        }
    });
}

function renderMarkdown(content: string | null): string {
    if (!content) {
        return '';
    }
    const raw = marked.parse(content, { async: false, breaks: true, gfm: true }) as string;
    return DOMPurify.sanitize(raw);
}

watch(messages, scrollToBottom, { deep: true });

onUnmounted(unsubscribe);
</script>

<template>
    <div class="ra-root">
        <!-- Launcher -->
        <button
            type="button"
            class="ra-launcher"
            :class="dark ? 'ra-launcher--dark' : 'ra-launcher--light'"
            :aria-label="open ? 'Close assistant' : 'Open assistant'"
            @click="toggle"
        >
            <svg v-if="!open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="ra-icon">
                <path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="ra-icon">
                <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <!-- Panel -->
        <div v-if="open" class="ra-panel" :class="dark ? 'ra-panel--dark' : 'ra-panel--light'">
            <header class="ra-header">
                <div class="ra-title">{{ name }}</div>
                <button type="button" class="ra-close" aria-label="Close" @click="toggle">✕</button>
            </header>

            <div ref="listEl" class="ra-messages">
                <p v-if="messages.length === 0 && !starting" class="ra-empty">
                    Ask me about your data — I can read this app and answer.
                </p>

                <div
                    v-for="m in messages"
                    :key="m.id"
                    class="ra-msg"
                    :class="m.role === 'user' ? 'ra-msg--user' : 'ra-msg--assistant'"
                >
                    <div v-if="m.role === 'assistant'" class="ra-assistant">
                        <div
                            v-if="m.content"
                            class="ra-bubble ra-md"
                            :class="{ 'ra-bubble--error': m.status === 'error' }"
                            v-html="renderMarkdown(m.content)"
                        />

                        <!-- Proposed action card (the gate): approve to apply, dismiss to discard. -->
                        <div v-if="m.message_type === 'action_proposal'" class="ra-card">
                            <div class="ra-card-title">Proposed change</div>
                            <ul class="ra-card-previews">
                                <li v-for="(p, i) in m.action_payload?.previews ?? []" :key="i">{{ p }}</li>
                            </ul>

                            <div v-if="isPendingProposal(m)" class="ra-card-actions">
                                <button
                                    type="button"
                                    class="ra-approve"
                                    :disabled="actingId === m.id"
                                    @click="resolveProposal(m, 'approve')"
                                >
                                    {{ actingId === m.id ? 'Applying…' : 'Approve' }}
                                </button>
                                <button
                                    type="button"
                                    class="ra-dismiss"
                                    :disabled="actingId === m.id"
                                    @click="resolveProposal(m, 'dismiss')"
                                >
                                    Dismiss
                                </button>
                            </div>
                            <div v-else class="ra-card-status">
                                {{ m.action_payload?.status === 'executed' ? '✓ Applied' : 'Dismissed' }}
                            </div>
                        </div>
                    </div>
                    <div v-else class="ra-bubble ra-bubble--user">{{ m.content }}</div>
                </div>

                <p v-if="awaitingFirstToken" class="ra-thinking">{{ name }} is thinking…</p>
            </div>

            <p v-if="errorText" class="ra-error">{{ errorText }}</p>

            <div class="ra-composer">
                <textarea
                    v-model="input"
                    rows="1"
                    class="ra-input"
                    placeholder="Ask a question…"
                    :disabled="sending"
                    @keydown="onComposerKeydown"
                />
                <button type="button" class="ra-send" :disabled="sending || input.trim() === ''" @click="send">
                    Send
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ra-launcher {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 60;
    width: 3.25rem;
    height: 3.25rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
    cursor: pointer;
    border: none;
    transition: transform 0.15s ease;
}
.ra-launcher:hover {
    transform: translateY(-2px);
}
.ra-launcher--light {
    background: var(--sp-accent, #4f46e5);
    color: #fff;
}
.ra-launcher--dark {
    background: var(--sp-accent, #6366f1);
    color: #fff;
}
.ra-icon {
    width: 1.5rem;
    height: 1.5rem;
}

.ra-panel {
    position: fixed;
    right: 1.25rem;
    bottom: 5.25rem;
    z-index: 60;
    width: min(24rem, calc(100vw - 2.5rem));
    height: min(34rem, calc(100vh - 8rem));
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
}
.ra-panel--light {
    background: #fff;
    color: #0f172a;
    border: 1px solid #e2e8f0;
}
.ra-panel--dark {
    background: #0f172a;
    color: #e2e8f0;
    border: 1px solid #1e293b;
}

.ra-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid currentColor;
    border-color: rgba(148, 163, 184, 0.25);
}
.ra-title {
    font-weight: 600;
    font-size: 0.95rem;
}
.ra-close {
    background: none;
    border: none;
    cursor: pointer;
    color: inherit;
    opacity: 0.6;
    font-size: 0.9rem;
}
.ra-close:hover {
    opacity: 1;
}

.ra-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.ra-empty,
.ra-thinking {
    font-size: 0.85rem;
    opacity: 0.6;
    margin: 0;
}
.ra-thinking {
    font-style: italic;
}

.ra-msg {
    display: flex;
}
.ra-msg--user {
    justify-content: flex-end;
}
.ra-msg--assistant {
    justify-content: flex-start;
}
.ra-bubble {
    max-width: 85%;
    padding: 0.5rem 0.75rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}
.ra-bubble--user {
    background: var(--sp-accent, #4f46e5);
    color: #fff;
    border-bottom-right-radius: 0.25rem;
}
.ra-panel--light .ra-md {
    background: #f1f5f9;
    color: #0f172a;
    border-bottom-left-radius: 0.25rem;
}
.ra-panel--dark .ra-md {
    background: #1e293b;
    color: #e2e8f0;
    border-bottom-left-radius: 0.25rem;
}
.ra-bubble--error {
    background: #fee2e2 !important;
    color: #991b1b !important;
}

.ra-assistant {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: 85%;
}
.ra-card {
    border-radius: 0.75rem;
    padding: 0.65rem 0.75rem;
    font-size: 0.8rem;
    border: 1px solid var(--sp-accent, #4f46e5);
}
.ra-panel--light .ra-card {
    background: #f8fafc;
}
.ra-panel--dark .ra-card {
    background: #1e293b;
}
.ra-card-title {
    font-weight: 600;
    margin-bottom: 0.35rem;
}
.ra-card-previews {
    list-style: disc;
    margin: 0 0 0.5rem;
    padding-left: 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}
.ra-card-actions {
    display: flex;
    gap: 0.5rem;
}
.ra-approve,
.ra-dismiss {
    border: none;
    border-radius: 0.4rem;
    padding: 0.35rem 0.75rem;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
}
.ra-approve {
    background: var(--sp-accent, #4f46e5);
    color: #fff;
}
.ra-approve:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.ra-dismiss {
    background: transparent;
    color: inherit;
    border: 1px solid rgba(148, 163, 184, 0.5);
}
.ra-card-status {
    font-weight: 600;
    opacity: 0.7;
}

.ra-error {
    font-size: 0.8rem;
    color: #dc2626;
    padding: 0 1rem;
    margin: 0 0 0.5rem;
}

.ra-composer {
    display: flex;
    gap: 0.5rem;
    padding: 0.75rem;
    border-top: 1px solid rgba(148, 163, 184, 0.25);
}
.ra-input {
    flex: 1;
    resize: none;
    border-radius: 0.5rem;
    padding: 0.5rem 0.65rem;
    font-size: 0.875rem;
    font-family: inherit;
    border: 1px solid rgba(148, 163, 184, 0.4);
    background: transparent;
    color: inherit;
    max-height: 7rem;
}
.ra-input:focus {
    outline: none;
    border-color: var(--sp-accent, #4f46e5);
}
.ra-send {
    align-self: flex-end;
    border: none;
    border-radius: 0.5rem;
    padding: 0.5rem 0.9rem;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    background: var(--sp-accent, #4f46e5);
    color: #fff;
}
.ra-send:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ra-md :deep(p) {
    margin: 0.25rem 0;
}
.ra-md :deep(p:first-child) {
    margin-top: 0;
}
.ra-md :deep(p:last-child) {
    margin-bottom: 0;
}
.ra-md :deep(ul),
.ra-md :deep(ol) {
    margin: 0.35rem 0;
    padding-left: 1.2rem;
}
.ra-md :deep(code) {
    font-family: ui-monospace, monospace;
    font-size: 0.8em;
    background: rgba(148, 163, 184, 0.2);
    padding: 0.05rem 0.3rem;
    border-radius: 0.25rem;
}
.ra-md :deep(pre) {
    background: rgba(15, 23, 42, 0.85);
    color: #e2e8f0;
    padding: 0.65rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    font-size: 0.8em;
}
.ra-md :deep(pre code) {
    background: none;
    padding: 0;
}
.ra-md :deep(a) {
    color: var(--sp-accent, #4f46e5);
    text-decoration: underline;
}
.ra-md :deep(table) {
    border-collapse: collapse;
    font-size: 0.8em;
    margin: 0.35rem 0;
}
.ra-md :deep(th),
.ra-md :deep(td) {
    border: 1px solid rgba(148, 163, 184, 0.4);
    padding: 0.2rem 0.4rem;
}
</style>
