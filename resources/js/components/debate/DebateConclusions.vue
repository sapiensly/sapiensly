<script setup lang="ts">
import { accentClasses, renderMarkdown } from '@/lib/debate';
import type { DebateParticipantDto, DebateTurnDto } from '@/types/debateModule';
import { Check, Copy, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    turn: DebateTurnDto | null;
    participants: DebateParticipantDto[];
}>();

const streaming = computed(
    () =>
        !props.turn ||
        props.turn.status === 'pending' ||
        props.turn.status === 'streaming',
);

const stanceStyles: Record<string, string> = {
    agree: 'bg-emerald-500/15 text-emerald-500',
    partial: 'bg-amber-500/15 text-amber-500',
    dissent: 'bg-rose-500/15 text-rose-500',
};

function stanceLabel(stance: string | null): string {
    if (!stance) return t('debate.conclusions.stance_unknown');
    return t(`debate.conclusions.stance_${stance}`);
}

const copied = ref(false);
async function copy() {
    if (!props.turn?.content) return;
    try {
        await navigator.clipboard.writeText(props.turn.content);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        /* clipboard unavailable */
    }
}
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-accent-blue/30 bg-accent-blue/[0.04]"
    >
        <div
            class="flex items-center gap-2 border-b border-accent-blue/20 px-4 py-3"
        >
            <span
                class="flex size-7 items-center justify-center rounded-lg bg-accent-blue/15"
            >
                <Sparkles class="size-4 text-accent-blue" />
            </span>
            <p class="flex-1 text-sm font-semibold text-ink">
                {{ t('debate.conclusions.title') }}
            </p>
            <button
                v-if="turn?.content && !streaming"
                type="button"
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] text-ink-subtle transition-colors hover:bg-white/10 hover:text-ink"
                @click="copy"
            >
                <Check v-if="copied" class="size-3.5 text-emerald-500" />
                <Copy v-else class="size-3.5" />
                {{
                    copied
                        ? t('debate.conclusions.copied')
                        : t('debate.conclusions.copy')
                }}
            </button>
        </div>

        <div class="p-5">
            <div
                v-if="turn?.status === 'error'"
                class="rounded-lg border border-sp-danger/30 bg-sp-danger/10 p-3 text-sm text-sp-danger"
            >
                {{ turn.error || t('debate.conclusions.error') }}
            </div>
            <template v-else>
                <div
                    v-if="turn?.content"
                    class="sp-chat-prose prose prose-sm max-w-none dark:prose-invert"
                    v-html="renderMarkdown(turn.content)"
                />
                <div
                    v-else
                    class="flex items-center gap-2 py-2 text-sm text-ink-subtle"
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
                    {{ t('debate.conclusions.writing') }}
                </div>
                <span
                    v-if="turn?.status === 'streaming' && turn?.content"
                    class="ml-0.5 inline-block h-4 w-[3px] translate-y-0.5 animate-pulse rounded-full bg-accent-blue"
                />

                <!-- Per-model final stance -->
                <div
                    v-if="!streaming && participants.length"
                    class="mt-5 border-t border-soft pt-4"
                >
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase"
                    >
                        {{ t('debate.conclusions.final_stance') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="p in participants"
                            :key="p.id"
                            class="inline-flex items-center gap-1.5 rounded-full border border-soft bg-navy px-2.5 py-1 text-xs"
                        >
                            <span
                                :class="[
                                    'size-2 rounded-full',
                                    accentClasses(p.accent).dot,
                                ]"
                            />
                            <span class="font-medium text-ink">{{
                                p.display_name
                            }}</span>
                            <span
                                :class="[
                                    'rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                                    stanceStyles[p.final_stance ?? ''] ??
                                        'bg-white/10 text-ink-subtle',
                                ]"
                            >
                                {{ stanceLabel(p.final_stance) }}
                            </span>
                        </span>
                    </div>
                </div>
            </template>
        </div>
    </section>
</template>
