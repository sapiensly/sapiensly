<script setup lang="ts">
import { normalizeChatMarkdown } from '@/lib/markdown';
import type { ConsultationDto } from '@/types/chatModule';
import { Loader2, MessagesSquare, Users } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, ref } from 'vue';
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

// Background consultations collapse to a pill; expand on click.
const open = ref(props.consultation.visible);
</script>

<template>
    <!-- Live: still waiting on the consulted agent. -->
    <div
        v-if="consultation.pending"
        class="mb-2 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
        :style="{
            borderColor: accentSoft,
            backgroundColor: accentSoft,
            color: accent,
        }"
    >
        <Loader2 class="size-3 animate-spin" />
        {{ t('chat.consult.consulting', { agent: consultation.agent_name }) }}
    </div>

    <!-- Resolved: a card (visible) or a collapsible pill (background). -->
    <div
        v-else
        class="mb-2 overflow-hidden rounded-lg border text-xs"
        :style="{ borderColor: accentSoft }"
    >
        <button
            type="button"
            class="flex w-full items-center gap-1.5 px-2.5 py-1.5 text-left font-medium transition-colors"
            :style="{ backgroundColor: accentSoft, color: accent }"
            @click="open = !open"
        >
            <Users class="size-3.5" />
            {{
                t('chat.consult.consulted', { agent: consultation.agent_name })
            }}
            <MessagesSquare v-if="!open" class="ml-auto size-3 opacity-60" />
        </button>
        <div v-if="open" class="space-y-1.5 px-2.5 py-2">
            <p class="text-ink-subtle">
                <span class="font-medium">{{ t('chat.consult.asked') }}:</span>
                {{ consultation.question }}
            </p>
            <div
                class="sp-chat-prose prose prose-sm max-w-none text-ink dark:prose-invert"
                v-html="renderMarkdown(consultation.answer)"
            />
        </div>
    </div>
</template>
