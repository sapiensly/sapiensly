<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { BotFlowDefinition } from '@/types/botFlows';
import axios from 'axios';
import { ChevronDown, Loader2, Send, Sparkles } from '@lucide/vue';
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
const collapsed = ref(false);
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
        error.value = t('flows.assistant.error');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div
        class="pointer-events-auto absolute top-3 left-1/2 z-10 flex max-h-[min(70%,520px)] w-[420px] max-w-[calc(100%-2rem)] -translate-x-1/2 flex-col rounded-sp-md border border-soft bg-navy/95 shadow-sp-float backdrop-blur"
    >
        <button
            type="button"
            class="flex w-full shrink-0 items-center gap-2 px-3.5 py-2.5 text-left"
            @click="collapsed = !collapsed"
        >
            <span
                class="flex size-6 shrink-0 items-center justify-center rounded-xs"
                style="background-color: color-mix(in oklab, #a855f7 18%, transparent); color: #a855f7"
            >
                <Sparkles class="size-3.5" />
            </span>
            <span class="flex-1 text-[13px] font-medium text-ink">
                {{ t('flows.assistant.title') }}
            </span>
            <ChevronDown
                class="size-4 text-ink-subtle transition-transform"
                :class="collapsed ? '-rotate-90' : ''"
            />
        </button>

        <template v-if="!collapsed">
            <!-- Thread -->
            <div
                ref="threadRef"
                class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3.5 pb-2"
            >
                <p
                    v-if="messages.length === 0"
                    class="py-1 text-[11px] leading-snug text-ink-subtle"
                >
                    {{ t('flows.assistant.hint') }}
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
                    {{ t('flows.assistant.thinking') }}
                </div>

                <p v-if="error" class="text-[11px] text-sp-danger">{{ error }}</p>
            </div>

            <!-- Composer -->
            <div class="shrink-0 border-t border-soft p-2.5">
                <Textarea
                    v-model="input"
                    :rows="2"
                    :placeholder="
                        messages.length === 0
                            ? t('flows.assistant.placeholder')
                            : t('flows.assistant.followup_placeholder')
                    "
                    :disabled="loading"
                    @keydown.enter.exact.prevent="send"
                />
                <div class="mt-2 flex items-center justify-between gap-2">
                    <span class="text-[10px] text-ink-subtle">
                        {{ t('flows.assistant.replace_warning') }}
                    </span>
                    <Button
                        size="sm"
                        :disabled="loading || !input.trim()"
                        @click="send"
                    >
                        <Loader2 v-if="loading" class="mr-1.5 size-3.5 animate-spin" />
                        <Send v-else class="mr-1.5 size-3.5" />
                        {{ t('flows.assistant.send') }}
                    </Button>
                </div>
            </div>
        </template>
    </div>
</template>
