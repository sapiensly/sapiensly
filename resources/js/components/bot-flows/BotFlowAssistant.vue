<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { BotFlowDefinition } from '@/types/botFlows';
import { Loader2, Send, Sparkles } from '@lucide/vue';
import axios from 'axios';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    /** Endpoint that takes { messages, spec } and returns { reply, spec, definition }. */
    converseUrl: string;
}>();

const emit = defineEmits<{
    generated: [definition: BotFlowDefinition];
}>();

interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
}

const messages = ref<ChatMessage[]>([]);
const spec = ref<Record<string, unknown> | null>(null);
const input = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const threadRef = ref<HTMLElement | null>(null);

const scrollToBottom = async () => {
    await nextTick();
    if (threadRef.value) {
        threadRef.value.scrollTop = threadRef.value.scrollHeight;
    }
};

const send = async () => {
    const content = input.value.trim();
    if (!content || loading.value) {
        return;
    }

    messages.value.push({ role: 'user', content });
    input.value = '';
    loading.value = true;
    error.value = null;
    await scrollToBottom();

    try {
        const { data } = await axios.post(props.converseUrl, {
            messages: messages.value,
            spec: spec.value,
        });
        spec.value = data.spec ?? spec.value;
        messages.value.push({ role: 'assistant', content: data.reply });
        emit('generated', data.definition as BotFlowDefinition);
        await scrollToBottom();
    } catch {
        error.value = t('botFlows.assistant.error');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <!-- Conversational creator panel (App Builder "Builder Chat" pattern). -->
    <div
        class="flex h-full w-full flex-col overflow-hidden border-r border-soft bg-navy"
    >
        <!-- Header -->
        <div
            class="flex shrink-0 items-center gap-2 border-b border-soft px-3.5 py-3"
        >
            <span
                class="flex size-6 shrink-0 items-center justify-center rounded-xs"
                style="
                    background-color: color-mix(
                        in oklab,
                        #a855f7 18%,
                        transparent
                    );
                    color: #a855f7;
                "
            >
                <Sparkles class="size-3.5" />
            </span>
            <span
                class="flex-1 text-[11px] font-semibold tracking-wide text-ink-muted uppercase"
            >
                {{ t('botFlows.assistant.title') }}
            </span>
        </div>

        <!-- Thread -->
        <div
            ref="threadRef"
            class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3.5 py-3"
        >
            <p
                v-if="messages.length === 0"
                class="text-xs leading-snug text-ink-subtle"
            >
                {{ t('botFlows.assistant.hint') }}
            </p>

            <div
                v-for="(m, i) in messages"
                :key="i"
                class="flex"
                :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
            >
                <div
                    class="max-w-[85%] rounded-sp-sm px-2.5 py-1.5 text-[12px] leading-snug whitespace-pre-wrap"
                    :class="
                        m.role === 'user'
                            ? 'bg-accent-blue/15 text-ink'
                            : 'border border-soft bg-white/[0.03] text-ink-muted'
                    "
                >
                    {{ m.content }}
                </div>
            </div>

            <div
                v-if="loading"
                class="flex items-center gap-1.5 text-[11px] text-ink-subtle"
            >
                <Loader2 class="size-3.5 animate-spin" />
                {{ t('botFlows.assistant.thinking') }}
            </div>

            <p v-if="error" class="text-[11px] text-sp-danger">{{ error }}</p>
        </div>

        <!-- Composer -->
        <div class="shrink-0 border-t border-soft p-2.5">
            <Textarea
                v-model="input"
                :rows="3"
                :placeholder="
                    messages.length === 0
                        ? t('botFlows.assistant.placeholder')
                        : t('botFlows.assistant.followup_placeholder')
                "
                :disabled="loading"
                @keydown.enter.exact.prevent="send"
            />
            <div class="mt-2 flex items-center justify-between gap-2">
                <span class="text-[10px] text-ink-subtle">
                    {{ t('botFlows.assistant.replace_warning') }}
                </span>
                <Button
                    size="sm"
                    :disabled="loading || !input.trim()"
                    @click="send"
                >
                    <Loader2
                        v-if="loading"
                        class="mr-1.5 size-3.5 animate-spin"
                    />
                    <Send v-else class="mr-1.5 size-3.5" />
                    {{ t('botFlows.assistant.send') }}
                </Button>
            </div>
        </div>
    </div>
</template>
