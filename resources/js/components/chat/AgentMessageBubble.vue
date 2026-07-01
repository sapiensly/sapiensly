<script setup lang="ts">
import AgentConsultationCard from '@/components/chat/AgentConsultationCard.vue';
import ArtifactCard from '@/components/chat/ArtifactCard.vue';
import ToolActivityChips from '@/components/chat/ToolActivityChips.vue';
import { type Artifact, parseArtifacts, type Segment } from '@/lib/artifacts';
import { normalizeChatMarkdown } from '@/lib/markdown';
import type {
    ChatMessageDto,
    ConsultationDto,
    ToolActivityDto,
} from '@/types/chatModule';
import { Bot } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    message: ChatMessageDto;
    toolActivity?: ToolActivityDto[] | null;
    consultations?: ConsultationDto[];
    activeArtifactId?: string | null;
}>();

const consultationCards = computed<ConsultationDto[]>(
    () => props.consultations ?? props.message.consultation_context ?? [],
);

defineEmits<{ openArtifact: [artifact: Artifact] }>();

const name = computed(() => props.message.agent?.name ?? 'Agent');

// Deterministic accent colour per agent so each speaker is visually distinct.
const hue = computed(() => {
    const key = props.message.agent?.id ?? name.value;
    let h = 0;
    for (let i = 0; i < key.length; i++) {
        h = (h * 31 + key.charCodeAt(i)) % 360;
    }
    return h;
});
const accent = computed(() => `hsl(${hue.value} 70% 55%)`);
const accentSoft = computed(() => `hsl(${hue.value} 70% 55% / 0.14)`);

const pills = computed(() =>
    Object.entries(props.message.agent_data_context ?? {}),
);

function segments(): Segment[] {
    const settled =
        props.message.status === 'complete' || props.message.status === 'error';
    return parseArtifacts(props.message.content, props.message.id, settled)
        .segments;
}

function renderMarkdown(content: string | null): string {
    if (!content) return '';
    const raw = marked.parse(normalizeChatMarkdown(content), {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}
</script>

<template>
    <div class="group flex gap-4">
        <div
            class="flex size-9 shrink-0 items-center justify-center rounded-xl"
            :style="{ backgroundColor: accentSoft, color: accent }"
        >
            <Bot class="size-[18px]" />
        </div>
        <div class="min-w-0 flex-1 pt-1">
            <!-- Header: agent name + data-source badge -->
            <div class="mb-1 flex items-center gap-2">
                <span class="text-sm font-semibold" :style="{ color: accent }"
                    >@{{ name }}</span
                >
                <span
                    v-if="pills.length"
                    class="truncate text-[11px] text-ink-subtle"
                >
                    {{ pills.map(([k]) => k).join(' · ') }}
                </span>
            </div>

            <div
                v-if="message.status === 'error'"
                class="rounded-2xl border border-sp-danger/30 bg-sp-danger/10 p-3.5 text-sm text-sp-danger"
            >
                {{
                    message.error || message.content || t('chat.error_generic')
                }}
            </div>
            <template v-else>
                <ToolActivityChips
                    :items="toolActivity ?? []"
                    :accent="accent"
                    :accent-soft="accentSoft"
                />
                <AgentConsultationCard
                    v-for="c in consultationCards"
                    :key="c.id"
                    :consultation="c"
                />

                <template v-for="(seg, si) in segments()" :key="si">
                    <ArtifactCard
                        v-if="seg.kind === 'artifact'"
                        :artifact="seg.artifact"
                        :active="activeArtifactId === seg.artifact.id"
                        @open="$emit('openArtifact', $event)"
                    />
                    <div
                        v-else
                        class="sp-chat-prose prose prose-sm max-w-none dark:prose-invert"
                        v-html="renderMarkdown(seg.text)"
                    />
                </template>

                <span
                    v-if="
                        message.status === 'pending' ||
                        message.status === 'streaming'
                    "
                    class="inline-block h-[18px] w-[3px] translate-y-1 animate-pulse rounded-full"
                    :class="{ 'ml-0.5': message.content }"
                    :style="{ backgroundColor: accent }"
                />

                <!-- Data pills -->
                <div
                    v-if="pills.length && message.status === 'complete'"
                    class="mt-2 flex flex-wrap gap-1.5"
                >
                    <span
                        v-for="[key, value] in pills"
                        :key="key"
                        class="inline-flex items-center gap-1 rounded-pill px-2 py-0.5 text-[11px] font-medium"
                        :style="{ backgroundColor: accentSoft, color: accent }"
                    >
                        <span class="opacity-80">{{ key }}:</span> {{ value }}
                    </span>
                </div>
            </template>
        </div>
    </div>
</template>
