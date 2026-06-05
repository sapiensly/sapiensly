<script setup lang="ts">
import ConsensusMeter from '@/components/debate/ConsensusMeter.vue';
import type { DebateStatus } from '@/types/debateModule';
import { Square } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    topic: string;
    title: string | null;
    status: DebateStatus;
    currentRound: number;
    maxRounds: number;
    consensusScore: number | null;
    consensusReached: boolean;
    busy: boolean;
}>();

defineEmits<{ stop: [] }>();

const statusStyle = computed<string>(() => {
    switch (props.status) {
        case 'completed':
            return 'bg-emerald-500/15 text-emerald-500';
        case 'converged':
            return 'bg-accent-blue/15 text-accent-blue';
        case 'failed':
        case 'stopped':
            return 'bg-rose-500/15 text-rose-500';
        default:
            return 'bg-amber-500/15 text-amber-500';
    }
});

const showRounds = computed(() =>
    ['debating', 'assessing'].includes(props.status),
);
</script>

<template>
    <div class="border-b border-soft bg-navy/60 px-6 py-3.5 backdrop-blur">
        <div class="mx-auto w-full max-w-[1100px]">
            <div class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink">
                        {{ title || topic }}
                    </p>
                    <p v-if="title" class="truncate text-xs text-ink-subtle">
                        {{ topic }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium',
                            statusStyle,
                        ]"
                    >
                        <span class="relative flex size-1.5">
                            <span
                                v-if="busy"
                                class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60"
                            />
                            <span
                                class="relative inline-flex size-1.5 rounded-full bg-current"
                            />
                        </span>
                        {{ t(`debate.status.${status}`) }}
                    </span>
                    <span
                        v-if="showRounds"
                        class="text-[11px] font-medium text-ink-subtle"
                    >
                        {{
                            t('debate.round.counter', {
                                current: currentRound,
                                max: maxRounds,
                            })
                        }}
                    </span>
                    <button
                        v-if="busy"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full bg-accent-blue px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-accent-blue-hover"
                        @click="$emit('stop')"
                    >
                        <Square class="size-3 fill-current" />
                        {{ t('debate.stop') }}
                    </button>
                </div>
            </div>

            <div class="mt-2.5 flex items-center gap-3">
                <span
                    class="shrink-0 text-[11px] font-medium tracking-wide text-ink-subtle uppercase"
                >
                    {{ t('debate.consensus') }}
                </span>
                <ConsensusMeter
                    class="flex-1"
                    :score="consensusScore"
                    :reached="consensusReached"
                    size="sm"
                />
            </div>
        </div>
    </div>
</template>
