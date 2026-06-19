<script setup lang="ts">
/**
 * The plan proposal card — discovery-as-proposal (FR-1). Shown BEFORE the
 * builder edits the manifest: it names the trigger, the ordered steps, every
 * external system touched (read/write blast radius), and the assumptions it
 * made as one-touch defaults. The user approves (Build it), edits an
 * assumption, or discards — nothing is built until they approve.
 *
 * Reuses the blast-radius ribbon grammar (read = cool/accent-blue,
 * write = warm/amber + lock) shared with the canvas node and the side panel.
 */

import { ArrowRight, Lock } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

type Effect = 'read' | 'write' | null;

interface PlanStep {
    label?: string;
    effect?: Effect;
    integration?: string;
}
interface PlanTouch {
    system?: string;
    effect?: Effect;
}
interface PlanAssumption {
    label?: string;
    default?: string;
}
export interface Plan {
    summary?: string;
    trigger?: string;
    steps?: PlanStep[];
    touches?: PlanTouch[];
    assumptions?: PlanAssumption[];
}

defineProps<{ plan: Plan }>();

const emit = defineEmits<{
    (e: 'build'): void;
    (e: 'discard'): void;
    (e: 'change', assumption: PlanAssumption): void;
}>();

const { t } = useI18n();
</script>

<template>
    <div
        class="mr-8 overflow-hidden rounded-sp-sm border border-soft bg-navy/50"
    >
        <!-- Header: the outcome, restated -->
        <div class="border-b border-soft px-3 py-2.5">
            <span class="text-[10px] tracking-wider text-ink-subtle uppercase">
                {{ t('apps.builder.plan.heading') }}
            </span>
            <p v-if="plan.summary" class="mt-0.5 text-sm font-medium text-ink">
                {{ plan.summary }}
            </p>
        </div>

        <div class="space-y-3 px-3 py-3">
            <!-- Trigger -->
            <div v-if="plan.trigger" class="flex items-start gap-2 text-xs">
                <span
                    class="shrink-0 rounded-pill bg-surface px-1.5 py-0.5 text-[10px] tracking-wider text-ink-subtle uppercase"
                >
                    {{ t('apps.builder.plan.trigger') }}
                </span>
                <span class="text-ink-muted">{{ plan.trigger }}</span>
            </div>

            <!-- Ordered steps -->
            <ol v-if="plan.steps?.length" class="space-y-1.5">
                <li
                    v-for="(step, i) in plan.steps"
                    :key="i"
                    class="flex items-center gap-2 text-sm text-ink"
                >
                    <span
                        class="w-4 shrink-0 text-right font-mono text-[11px] text-ink-subtle"
                        >{{ i + 1 }}</span
                    >
                    <span class="min-w-0 flex-1 truncate">
                        {{ step.label }}
                        <span v-if="step.integration" class="text-ink-subtle"
                            >· {{ step.integration }}</span
                        >
                    </span>
                    <span
                        v-if="step.effect"
                        class="inline-flex shrink-0 items-center gap-1 rounded-pill px-1.5 py-0.5 text-[10px] font-medium tracking-wider uppercase"
                        :class="
                            step.effect === 'write'
                                ? 'bg-amber-400/10 text-amber-300'
                                : 'bg-accent-blue/10 text-accent-blue'
                        "
                    >
                        {{
                            step.effect === 'write'
                                ? t('apps.builder.plan.write')
                                : t('apps.builder.plan.read')
                        }}
                        <Lock v-if="step.effect === 'write'" class="size-2.5" />
                    </span>
                </li>
            </ol>

            <!-- Touches: the blast radius before anything is built (FR-1.3) -->
            <div
                v-if="plan.touches?.length"
                class="flex flex-wrap items-center gap-1.5 border-t border-soft pt-2.5"
            >
                <span
                    class="text-[10px] tracking-wider text-ink-subtle uppercase"
                >
                    {{ t('apps.builder.plan.touches') }}
                </span>
                <span
                    v-for="(touch, i) in plan.touches"
                    :key="i"
                    class="inline-flex items-center gap-1 rounded-pill px-1.5 py-0.5 text-[10px] font-medium"
                    :class="
                        touch.effect === 'write'
                            ? 'bg-amber-400/10 text-amber-300'
                            : 'bg-accent-blue/10 text-accent-blue'
                    "
                >
                    {{ touch.system }}
                    <span class="tracking-wider uppercase opacity-70"
                        >·
                        {{
                            touch.effect === 'write'
                                ? t('apps.builder.plan.write')
                                : t('apps.builder.plan.read')
                        }}</span
                    >
                </span>
            </div>

            <!-- Assumptions: default + one-touch change, never a blank field (FR-1.2) -->
            <div
                v-if="plan.assumptions?.length"
                class="space-y-1 border-t border-soft pt-2.5"
            >
                <span
                    class="text-[10px] tracking-wider text-ink-subtle uppercase"
                >
                    {{ t('apps.builder.plan.assumptions') }}
                </span>
                <div
                    v-for="(assumption, i) in plan.assumptions"
                    :key="i"
                    class="flex items-center justify-between gap-2 text-xs"
                >
                    <span class="min-w-0 truncate text-ink-muted">
                        {{ assumption.label
                        }}<span v-if="assumption.default" class="text-ink">
                            · {{ assumption.default }}</span
                        >
                    </span>
                    <button
                        type="button"
                        class="shrink-0 rounded-xs px-1.5 py-0.5 text-[10px] tracking-wider text-accent-blue uppercase transition-colors hover:bg-accent-blue/10"
                        @click="emit('change', assumption)"
                    >
                        {{ t('apps.builder.plan.change') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div
            class="flex items-center justify-end gap-2 border-t border-soft px-3 py-2.5"
        >
            <button
                type="button"
                class="rounded-xs px-2.5 py-1 text-[11px] text-ink-muted transition-colors hover:bg-surface hover:text-ink"
                @click="emit('discard')"
            >
                {{ t('apps.builder.plan.discard') }}
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-pill bg-accent-blue px-3 py-1 text-[11px] font-medium text-white transition-colors hover:bg-accent-blue/90"
                @click="emit('build')"
            >
                {{ t('apps.builder.plan.build') }}
                <ArrowRight class="size-3" />
            </button>
        </div>
    </div>
</template>
