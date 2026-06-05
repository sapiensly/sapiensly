<script setup lang="ts">
import ConsensusMeter from '@/components/debate/ConsensusMeter.vue';
import type { DebateRoundDto } from '@/types/debateModule';
import { Check, Scale, X } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{ round: DebateRoundDto }>();

const summary = computed(() => props.round.consensus_summary);
const assessing = computed(
    () => props.round.status === 'running' || summary.value === null,
);
</script>

<template>
    <div class="rounded-2xl border border-soft bg-surface p-4">
        <div class="mb-2.5 flex items-center gap-2">
            <span
                class="flex size-7 items-center justify-center rounded-lg bg-accent-blue/10"
            >
                <Scale class="size-4 text-accent-blue" />
            </span>
            <p class="flex-1 text-sm font-semibold text-ink">
                {{ t('debate.moderator.title') }}
            </p>
            <span
                v-if="round.consensus_reached"
                class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-500"
            >
                <Check class="size-3" />
                {{ t('debate.moderator.consensus') }}
            </span>
        </div>

        <div
            v-if="assessing"
            class="flex items-center gap-2 py-1 text-xs text-ink-subtle"
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
            {{ t('debate.moderator.assessing') }}
        </div>

        <template v-else>
            <ConsensusMeter
                :score="round.consensus_score"
                :reached="round.consensus_reached"
                size="sm"
                class="mb-3"
            />

            <p
                v-if="summary?.verdict"
                class="mb-3 text-[13px] leading-relaxed text-ink-muted"
            >
                {{ summary.verdict }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div v-if="summary?.agreements?.length">
                    <p
                        class="mb-1 flex items-center gap-1 text-[11px] font-semibold tracking-wide text-emerald-500 uppercase"
                    >
                        <Check class="size-3" />
                        {{ t('debate.moderator.agreements') }}
                    </p>
                    <ul class="space-y-1">
                        <li
                            v-for="(a, i) in summary.agreements"
                            :key="i"
                            class="flex gap-1.5 text-[13px] text-ink-muted"
                        >
                            <span
                                class="mt-1.5 size-1 shrink-0 rounded-full bg-emerald-500"
                            />
                            <span>{{ a }}</span>
                        </li>
                    </ul>
                </div>
                <div v-if="summary?.disagreements?.length">
                    <p
                        class="mb-1 flex items-center gap-1 text-[11px] font-semibold tracking-wide text-amber-500 uppercase"
                    >
                        <X class="size-3" />
                        {{ t('debate.moderator.disagreements') }}
                    </p>
                    <ul class="space-y-1">
                        <li
                            v-for="(d, i) in summary.disagreements"
                            :key="i"
                            class="flex gap-1.5 text-[13px] text-ink-muted"
                        >
                            <span
                                class="mt-1.5 size-1 shrink-0 rounded-full bg-amber-500"
                            />
                            <span>{{ d }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
</template>
