<script setup lang="ts">
import DebateParticipantCard from '@/components/debate/DebateParticipantCard.vue';
import ModeratorStrip from '@/components/debate/ModeratorStrip.vue';
import type {
    DebateParticipantDto,
    DebateRoundDto,
    DebateTurnDto,
} from '@/types/debateModule';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    round: DebateRoundDto;
    participants: DebateParticipantDto[];
    defaultOpen?: boolean;
}>();

const open = ref(props.defaultOpen ?? true);

// Re-open a round when it becomes the active one again.
watch(
    () => props.defaultOpen,
    (v) => {
        if (v) open.value = true;
    },
);

function turnFor(p: DebateParticipantDto): DebateTurnDto | null {
    return (
        props.round.turns.find((tn) => tn.debate_participant_id === p.id) ??
        null
    );
}

const participantTurns = computed(() =>
    props.round.turns.filter((tn) => tn.role === 'participant'),
);
const allSettled = computed(
    () =>
        participantTurns.value.length > 0 &&
        participantTurns.value.every(
            (tn) => tn.status === 'complete' || tn.status === 'error',
        ),
);
const showModerator = computed(
    () => props.round.consensus_summary !== null || allSettled.value,
);

const label = computed(() => {
    const type =
        props.round.type === 'opening'
            ? t('debate.round.opening')
            : t('debate.round.rebuttal');
    return t('debate.round.label', { number: props.round.round_number, type });
});

// Grid columns scale with participant count, capped for readability.
const gridClass = computed(() => {
    const n = props.participants.length;
    if (n <= 1) return 'grid-cols-1';
    if (n === 2) return 'sm:grid-cols-2';
    if (n === 4) return 'sm:grid-cols-2 xl:grid-cols-2';
    return 'sm:grid-cols-2 xl:grid-cols-3';
});
</script>

<template>
    <section class="rounded-2xl border border-soft bg-surface/40">
        <button
            type="button"
            class="flex w-full items-center gap-2 px-4 py-3 text-left"
            @click="open = !open"
        >
            <ChevronDown
                :class="[
                    'size-4 text-ink-subtle transition-transform',
                    open ? '' : '-rotate-90',
                ]"
            />
            <span class="text-sm font-semibold text-ink">{{ label }}</span>
            <span
                v-if="round.consensus_reached"
                class="ml-auto inline-flex items-center rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-500"
            >
                {{ t('debate.moderator.consensus') }}
            </span>
        </button>

        <div v-if="open" class="space-y-3 px-4 pb-4">
            <div :class="['grid gap-3', gridClass]">
                <DebateParticipantCard
                    v-for="p in participants"
                    :key="p.id"
                    :participant="p"
                    :turn="turnFor(p)"
                />
            </div>

            <ModeratorStrip v-if="showModerator" :round="round" />
        </div>
    </section>
</template>
