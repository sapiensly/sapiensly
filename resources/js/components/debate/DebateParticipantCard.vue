<script setup lang="ts">
import { accentClasses, renderMarkdown } from '@/lib/debate';
import type { DebateParticipantDto, DebateTurnDto } from '@/types/debateModule';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    participant: DebateParticipantDto;
    turn: DebateTurnDto | null;
}>();

const accent = computed(() => accentClasses(props.participant.accent));
const status = computed(() => props.turn?.status ?? 'pending');
const streaming = computed(
    () => status.value === 'pending' || status.value === 'streaming',
);
</script>

<template>
    <div
        :class="[
            'flex min-h-0 flex-col rounded-2xl border bg-navy p-4 transition-colors',
            status === 'error' ? 'border-sp-danger/40' : 'border-soft',
        ]"
    >
        <!-- Header -->
        <div class="mb-2 flex items-center gap-2">
            <span
                :class="[
                    'flex size-7 shrink-0 items-center justify-center rounded-lg',
                    accent.soft,
                ]"
            >
                <Sparkles :class="['size-4', accent.text]" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink">
                    {{ participant.display_name }}
                </p>
                <p
                    class="truncate text-[10px] tracking-wide text-ink-subtle uppercase"
                >
                    {{ participant.provider }}
                </p>
            </div>
            <span
                v-if="status === 'complete'"
                :class="[
                    'inline-block size-2 shrink-0 rounded-full',
                    accent.dot,
                ]"
                :title="t('debate.card.done')"
            />
        </div>

        <!-- Stance chip -->
        <div
            v-if="turn?.stance_summary"
            :class="[
                'mb-2 rounded-lg border px-2.5 py-1.5 text-xs font-medium',
                accent.border,
                accent.soft,
            ]"
        >
            <span :class="accent.text">{{ t('debate.card.position') }}:</span>
            <span class="text-ink"> {{ turn.stance_summary }}</span>
        </div>

        <!-- Body -->
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                v-if="status === 'error'"
                class="rounded-lg border border-sp-danger/30 bg-sp-danger/10 p-2.5 text-xs text-sp-danger"
            >
                {{ turn?.error || t('debate.card.error') }}
            </div>
            <template v-else>
                <div
                    v-if="turn?.content"
                    class="sp-chat-prose prose prose-sm max-w-none text-[13.5px] dark:prose-invert"
                    v-html="renderMarkdown(turn.content)"
                />
                <div
                    v-else-if="streaming"
                    class="flex items-center gap-2 py-3 text-xs text-ink-subtle"
                >
                    <span class="inline-flex gap-1">
                        <span
                            class="size-1.5 animate-bounce rounded-full bg-ink-subtle [animation-delay:-0.3s]"
                        />
                        <span
                            class="size-1.5 animate-bounce rounded-full bg-ink-subtle [animation-delay:-0.15s]"
                        />
                        <span
                            class="size-1.5 animate-bounce rounded-full bg-ink-subtle"
                        />
                    </span>
                    {{ t('debate.card.thinking') }}
                </div>
                <span
                    v-if="status === 'streaming' && turn?.content"
                    class="ml-0.5 inline-block h-[15px] w-[3px] translate-y-0.5 animate-pulse rounded-full bg-accent-blue"
                />
            </template>
        </div>
    </div>
</template>
