<script setup lang="ts">
import ThinkingCursor from '@/components/ThinkingCursor.vue';
import { normalizeChatMarkdown } from '@/lib/markdown';
import type { ConsultationDto } from '@/types/chatModule';
import { ChevronDown, Loader2, Users } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{ consultation: ConsultationDto }>();

const { t } = useI18n();

// Render the consulted agent's answer as markdown, mirroring AgentMessageBubble.
function renderMarkdown(content: string | null): string {
    if (!content) return '';
    const raw = marked.parse(normalizeChatMarkdown(content), {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}

// Deterministic per-agent hue, mirroring AgentMessageBubble.
const hue = computed(() => {
    const key = props.consultation.agent_id || props.consultation.agent_name;
    let h = 0;
    for (let i = 0; i < key.length; i++) {
        h = (h * 31 + key.charCodeAt(i)) % 360;
    }
    return h;
});
const accent = computed(() => `hsl(${hue.value} 70% 45%)`);
const accentSoft = computed(() => `hsl(${hue.value} 70% 45% / 0.1)`);

// Closed by default — including while the agent is still writing. The user may
// open it to watch the answer stream in live; that stays their choice.
const open = ref(false);

// When a consultation the assistant wanted visible finishes, reveal its answer.
watch(
    () => props.consultation.pending,
    (pending, was) => {
        if (was && !pending && props.consultation.visible) open.value = true;
    },
);
</script>

<template>
    <div
        class="mb-2 overflow-hidden rounded-lg border text-xs"
        :style="{ borderColor: accentSoft }"
    >
        <button
            type="button"
            class="flex w-full items-center gap-1.5 px-2.5 py-1.5 text-left font-medium transition-colors"
            :style="{ backgroundColor: accentSoft, color: accent }"
            @click="open = !open"
        >
            <Loader2
                v-if="consultation.pending"
                class="size-3.5 shrink-0 animate-spin"
            />
            <Users v-else class="size-3.5 shrink-0" />
            <span class="truncate">
                {{
                    consultation.pending
                        ? t('chat.consult.consulting', {
                              agent: consultation.agent_name,
                          })
                        : t('chat.consult.consulted', {
                              agent: consultation.agent_name,
                          })
                }}
            </span>
            <!-- Pending gets an "in progress" tag; resolved gets a chevron. -->
            <span
                v-if="consultation.pending"
                class="ml-auto shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium tracking-wide uppercase opacity-70"
                :style="{ backgroundColor: accentSoft }"
            >
                {{ t('chat.consult.in_progress') }}
            </span>
            <ChevronDown
                v-else
                class="ml-auto size-3.5 shrink-0 opacity-60 transition-transform"
                :class="{ '-rotate-90': !open }"
            />
        </button>

        <div v-if="open" class="space-y-1.5 px-2.5 py-2">
            <p class="text-ink-subtle">
                <span class="font-medium">{{ t('chat.consult.asked') }}:</span>
                {{ consultation.question }}
            </p>
            <!-- The answer streams in live while pending; empty until the first token. -->
            <p
                v-if="consultation.pending && !consultation.answer"
                class="text-ink-subtle italic"
            >
                {{ t('chat.consult.thinking') }}
            </p>
            <div v-else>
                <div
                    class="sp-chat-prose prose prose-sm inline max-w-none text-ink dark:prose-invert"
                    v-html="renderMarkdown(consultation.answer)"
                />
                <ThinkingCursor
                    v-if="consultation.pending"
                    :size="12"
                    class="ml-0.5"
                    :style="{ color: accent }"
                />
            </div>
        </div>
    </div>
</template>
