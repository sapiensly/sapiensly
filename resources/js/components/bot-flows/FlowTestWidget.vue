<script setup lang="ts">
import * as BotFlowTestController from '@/actions/App/Http/Controllers/BotFlowTestController';
import { Button } from '@/components/ui/button';
import {
    Bot,
    Check,
    MessageSquare,
    Paperclip,
    RefreshCw,
    Send,
    X,
} from '@lucide/vue';
import axios from 'axios';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    flowId: string;
}

const props = defineProps<Props>();

interface MenuOption {
    id: string;
    label: string;
}

interface TestMessage {
    role: 'user' | 'assistant';
    content: string;
    options?: MenuOption[];
}

const isOpen = ref(false);
const messages = ref<TestMessage[]>([]);
const message = ref('');
const state = ref<Record<string, unknown> | null>(null);
const isLoading = ref(false);
const error = ref<string | null>(null);
const messagesContainer = ref<HTMLElement | null>(null);

// Index of the active menu — only clickable if no user message has been sent after it
const activeOptionsIndex = computed(() => {
    for (let i = messages.value.length - 1; i >= 0; i--) {
        const msg = messages.value[i];
        if (msg.role === 'user') {
            return -1;
        }
        if (msg.role === 'assistant' && msg.options && msg.options.length > 0) {
            return i;
        }
    }
    return -1;
});

/**
 * For a given menu message index, find the option id that the user picked.
 * Looks at the next user message after the menu and matches it to an option.
 */
function selectedOptionId(menuIndex: number): string | null {
    const menu = messages.value[menuIndex];
    if (!menu?.options) return null;

    for (let i = menuIndex + 1; i < messages.value.length; i++) {
        const next = messages.value[i];
        if (next.role !== 'user') continue;

        const input = next.content.trim();
        const inputLower = input.toLowerCase();

        // Match by 1-based index
        if (/^\d+$/.test(input)) {
            const idx = parseInt(input, 10) - 1;
            if (menu.options[idx]) return menu.options[idx].id;
        }

        // Match by exact label
        const exact = menu.options.find(
            (o) => o.label.toLowerCase() === inputLower,
        );
        if (exact) return exact.id;

        // Match by partial (contains)
        const partial = menu.options.find(
            (o) =>
                input.length >= 3 && o.label.toLowerCase().includes(inputLower),
        );
        if (partial) return partial.id;

        return null;
    }

    return null;
}

async function startSession() {
    isLoading.value = true;
    error.value = null;
    messages.value = [];

    try {
        const response = await axios.post(
            BotFlowTestController.start.url({ flow: props.flowId }),
        );
        state.value = response.data.state;
        messages.value = response.data.messages ?? [];
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value =
            axiosError.response?.data?.error ?? 'Failed to start test session.';
    } finally {
        isLoading.value = false;
    }
}

async function sendMessage(
    content?: string,
    attachments: { original_name: string; mime: string }[] = [],
) {
    const text = (content ?? message.value).trim();
    if (
        (!text && attachments.length === 0) ||
        isLoading.value ||
        !state.value
    ) {
        return;
    }

    if (content === undefined) {
        message.value = '';
    }

    const fileLabel = attachments.map((a) => `📎 ${a.original_name}`).join(' ');
    messages.value.push({
        role: 'user',
        content: [text, fileLabel].filter(Boolean).join(' '),
    });
    isLoading.value = true;

    try {
        const response = await axios.post(
            BotFlowTestController.send.url({ flow: props.flowId }),
            {
                state: state.value,
                message: text,
                attachments,
            },
        );
        state.value = response.data.state;
        const newMessages = response.data.messages ?? [];
        messages.value.push(...newMessages);
    } catch (e: unknown) {
        const axiosError = e as {
            response?: { data?: { error?: string; message?: string } };
        };
        error.value =
            axiosError.response?.data?.error ??
            axiosError.response?.data?.message ??
            'Failed to send message.';
    } finally {
        isLoading.value = false;
    }
}

function selectOption(option: MenuOption) {
    sendMessage(option.label);
}

const fileInput = ref<HTMLInputElement | null>(null);

function pickFile() {
    fileInput.value?.click();
}

function onFilePicked(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    target.value = '';
    if (!file) return;

    // Test mode simulates the upload — only the type metadata is sent, which is
    // all the runtime routes on. The bytes are never stored.
    const text = message.value.trim();
    message.value = '';
    sendMessage(text, [
        {
            original_name: file.name,
            mime: file.type || 'application/octet-stream',
        },
    ]);
}

function reset() {
    startSession();
}

function open() {
    isOpen.value = true;
    if (messages.value.length === 0) {
        startSession();
    }
}

watch(
    messages,
    () => {
        nextTick(() => {
            if (messagesContainer.value) {
                messagesContainer.value.scrollTop =
                    messagesContainer.value.scrollHeight;
            }
        });
    },
    { deep: true },
);
</script>

<template>
    <div class="absolute right-4 bottom-4 z-50">
        <!-- Floating button when closed -->
        <button
            v-if="!isOpen"
            class="flex h-14 items-center gap-2 rounded-pill bg-accent-blue pr-5 pl-4 text-white shadow-btn-primary transition-transform hover:scale-105"
            title="Test flow"
            @click="open"
        >
            <MessageSquare class="h-6 w-6" />
            <span class="text-xs font-bold tracking-wider">{{
                t('botFlows.test.preview')
            }}</span>
        </button>

        <!-- Chat window when open -->
        <div
            v-else
            class="flex h-[520px] w-[380px] flex-col overflow-hidden rounded-sp-sm border border-soft bg-navy shadow-sp-image"
        >
            <!-- Header -->
            <div
                class="flex items-center justify-between border-b border-soft bg-accent-blue px-4 py-3 text-white"
            >
                <div class="flex items-center gap-2">
                    <Bot class="h-4 w-4" />
                    <span class="text-sm font-semibold">BotFlow Test</span>
                </div>
                <div class="flex items-center gap-1">
                    <button
                        class="rounded p-1 text-white/80 transition-colors hover:bg-surface-hover hover:text-white"
                        title="Reset conversation"
                        @click="reset"
                    >
                        <RefreshCw class="h-4 w-4" />
                    </button>
                    <button
                        class="rounded p-1 text-white/80 transition-colors hover:bg-surface-hover hover:text-white"
                        @click="isOpen = false"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- Error -->
            <div
                v-if="error"
                class="border-b border-sp-danger/30 bg-sp-danger/10 px-3 py-2 text-xs text-sp-danger"
            >
                {{ error }}
            </div>

            <!-- Hint -->
            <div
                class="border-b border-sp-warning/30 bg-sp-warning/10 px-3 py-2 text-xs text-sp-warning"
            >
                Save your flow before testing. Reset to apply changes.
            </div>

            <!-- Messages -->
            <div
                ref="messagesContainer"
                class="flex-1 space-y-3 overflow-y-auto p-4"
            >
                <template v-for="(msg, index) in messages" :key="index">
                    <div
                        class="flex"
                        :class="
                            msg.role === 'user'
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <div
                            v-if="msg.content"
                            class="max-w-[85%] rounded-sp-sm px-3 py-2 text-sm whitespace-pre-wrap"
                            :class="
                                msg.role === 'user'
                                    ? 'bg-accent-blue text-white'
                                    : 'bg-surface text-ink'
                            "
                        >
                            {{ msg.content }}
                        </div>
                    </div>

                    <div
                        v-if="
                            msg.role === 'assistant' &&
                            msg.options &&
                            msg.options.length > 0
                        "
                        class="flex flex-col items-start gap-1.5 pl-1"
                    >
                        <button
                            v-for="opt in msg.options"
                            :key="opt.id"
                            type="button"
                            class="inline-flex items-center justify-start gap-1 rounded-pill border px-3 py-1 text-left text-xs font-medium transition-colors"
                            :class="
                                index === activeOptionsIndex
                                    ? 'cursor-pointer border-accent-blue/40 bg-accent-blue/10 text-accent-blue hover:bg-accent-blue hover:text-white'
                                    : 'cursor-not-allowed border-soft bg-surface text-ink-subtle'
                            "
                            :disabled="
                                index !== activeOptionsIndex || isLoading
                            "
                            @click="selectOption(opt)"
                        >
                            <Check
                                v-if="opt.id === selectedOptionId(index)"
                                class="h-3 w-3"
                            />
                            {{ opt.label }}
                        </button>
                    </div>
                </template>

                <div v-if="isLoading" class="flex justify-start">
                    <div
                        class="rounded-sp-sm bg-surface px-3 py-2 text-sm text-ink-muted"
                    >
                        ...
                    </div>
                </div>
            </div>

            <!-- Input -->
            <form
                class="flex gap-2 border-t border-soft p-3"
                @submit.prevent="sendMessage()"
            >
                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    @change="onFilePicked"
                />
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    :disabled="isLoading || !state"
                    title="Attach a test file"
                    @click="pickFile"
                >
                    <Paperclip class="h-4 w-4" />
                </Button>
                <input
                    v-model="message"
                    type="text"
                    placeholder="Type a message..."
                    :disabled="isLoading || !state"
                    class="flex-1 rounded-xs border border-medium bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-subtle focus:border-accent-blue focus:ring-1 focus:ring-accent-blue focus:outline-none disabled:opacity-50"
                />
                <Button
                    type="submit"
                    size="icon"
                    :disabled="isLoading || !message.trim() || !state"
                >
                    <Send class="h-4 w-4" />
                </Button>
            </form>
        </div>
    </div>
</template>
