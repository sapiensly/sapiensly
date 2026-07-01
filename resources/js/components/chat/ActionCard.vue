<script setup lang="ts">
import { type Artifact, documentArtifactFromProposal } from '@/lib/artifacts';
import { normalizeChatMarkdown } from '@/lib/markdown';
import type {
    ActionPayloadDto,
    ChatMessageDto,
    ChatSynthesisStatus,
} from '@/types/chatModule';
import { CircleCheck, FileText, Loader2, Sparkles, X } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    message: ChatMessageDto;
    // Thread-level synthesis status drives the executed/locked state.
    status?: ChatSynthesisStatus;
    busy?: boolean;
}>();

const emit = defineEmits<{
    execute: [];
    dismiss: [];
    openArtifact: [artifact: Artifact];
}>();

// This card only renders action_proposal messages; message_type guarantees the
// payload is an ActionPayloadDto (not the question shape sharing the column).
const payload = computed(
    () => props.message.action_payload as ActionPayloadDto | null,
);
// Only short scalar params make sense as pills; long values (a document `body`,
// a scaffold `description`) would blow up the card, so drop them.
const parameters = computed(() =>
    Object.entries(payload.value?.parameters ?? {}).filter(([, value]) => {
        if (value === null || typeof value === 'object') return false;
        return String(value).length <= 80;
    }),
);
// A single chat can hold several proposals, so the per-message status drives the
// card's locked state; fall back to the chat-level status for legacy proposals.
const executed = computed(
    () => payload.value?.status === 'executed' || props.status === 'executed',
);
const dismissed = computed(() => payload.value?.status === 'dismissed');

// A save_document proposal carries the document in its payload; expose it as a
// previewable artifact so the user can open it in the side panel and decide
// whether to keep it — before or after saving.
const documentArtifact = computed<Artifact | null>(() =>
    documentArtifactFromProposal(props.message.id, payload.value),
);

// The plain-language answer the user reads first. Older proposals lack it; in
// that case the action label stands in as the headline.
const summaryHtml = computed(() => {
    const summary = payload.value?.summary?.trim();
    if (!summary) return '';
    const raw = marked.parse(normalizeChatMarkdown(summary), {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
});

function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('');
}
</script>

<template>
    <div
        v-if="payload && !dismissed"
        class="overflow-hidden rounded-2xl border border-accent-blue/30 bg-accent-blue/[0.06] shadow-sm"
    >
        <div class="flex items-center justify-between gap-2 px-4 pt-3.5">
            <span
                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue/15 px-2.5 py-0.5 text-[10px] font-bold tracking-wider text-accent-blue uppercase"
            >
                <Sparkles class="size-3" />
                {{ t('chat.team.answer') }}
            </span>
            <button
                v-if="!executed"
                type="button"
                :title="t('chat.action.dismiss')"
                class="rounded p-1 text-ink-subtle transition-colors hover:bg-white/10 hover:text-ink"
                @click="$emit('dismiss')"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="px-4 pt-2 pb-4">
            <!-- The answer the user reads first. -->
            <div
                v-if="summaryHtml"
                class="sp-chat-prose prose prose-sm max-w-none text-ink dark:prose-invert"
                v-html="summaryHtml"
            />
            <h3 v-else class="text-[15px] font-semibold text-ink">
                {{ payload.action_label }}
            </h3>

            <!-- Agreed by -->
            <div
                v-if="payload.agreed_by?.length"
                class="mt-2 flex items-center gap-2"
            >
                <span class="text-[11px] text-ink-subtle">{{
                    t('chat.action.agreed_by')
                }}</span>
                <div class="flex -space-x-1.5">
                    <span
                        v-for="agentName in payload.agreed_by"
                        :key="agentName"
                        :title="agentName"
                        class="flex size-6 items-center justify-center rounded-full border border-surface bg-accent-blue/20 text-[10px] font-semibold text-accent-blue"
                    >
                        {{ initials(agentName) }}
                    </span>
                </div>
            </div>

            <!-- Parameter pills -->
            <div v-if="parameters.length" class="mt-3 flex flex-wrap gap-1.5">
                <span
                    v-for="[key, value] in parameters"
                    :key="key"
                    class="inline-flex items-center gap-1 rounded-lg border border-medium bg-surface px-2 py-1 text-[11px] text-ink"
                >
                    <span class="text-ink-subtle">{{ key }}:</span>
                    <span class="font-medium">{{ value }}</span>
                </span>
            </div>

            <p
                v-if="payload.rationale"
                class="mt-3 text-[12px] leading-relaxed text-ink-muted"
            >
                {{ payload.rationale }}
            </p>

            <!-- When a summary leads, restate the action the button will run. -->
            <p
                v-if="summaryHtml && !executed"
                class="mt-3 text-[12px] text-ink-subtle"
            >
                {{ t('chat.action.proposed') }}:
                <span class="font-medium text-ink">{{
                    payload.action_label
                }}</span>
            </p>

            <!-- Actions -->
            <div class="mt-4 flex items-center gap-2">
                <!-- Preview a proposed document in the side panel. -->
                <button
                    v-if="documentArtifact"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-medium bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:border-strong"
                    @click="emit('openArtifact', documentArtifact)"
                >
                    <FileText class="size-4 text-ink-subtle" />
                    {{ t('chat.action.view_document') }}
                </button>
                <div
                    v-if="executed"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-sp-success/15 px-3 py-2 text-sm font-medium text-sp-success"
                >
                    <CircleCheck class="size-4" />
                    {{ t('chat.action.executed') }}
                </div>
                <button
                    v-else
                    type="button"
                    :disabled="busy"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-accent-blue px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-blue-hover disabled:cursor-not-allowed disabled:opacity-60"
                    @click="$emit('execute')"
                >
                    <Loader2 v-if="busy" class="size-4 animate-spin" />
                    {{
                        busy
                            ? t('chat.action.executing')
                            : t('chat.action.execute')
                    }}
                </button>
            </div>
        </div>
    </div>
</template>
