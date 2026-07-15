<script setup lang="ts">
import {
    AlertCircle,
    CheckCircle2,
    Circle,
    ListChecks,
    Loader2,
    MinusCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface BuildPlanStep {
    id: string;
    title: string;
    detail?: string | null;
    status: 'pending' | 'in_progress' | 'done' | 'skipped' | 'failed';
    version_number?: number | null;
}

interface BuildPlan {
    goal?: string | null;
    status?: 'active' | 'done' | 'abandoned';
    steps: BuildPlanStep[];
}

const props = defineProps<{ plan: BuildPlan }>();

// Emits the step's title so the parent can pre-fill the composer with it —
// the "suggest the next step" affordance. Only actionable (pending/failed)
// steps emit.
const emit = defineEmits<{ (e: 'pick', title: string): void }>();

const doneCount = computed(
    () =>
        props.plan.steps.filter(
            (s) => s.status === 'done' || s.status === 'skipped',
        ).length,
);

const meta: Record<
    BuildPlanStep['status'],
    { icon: unknown; class: string; actionable: boolean }
> = {
    done: { icon: CheckCircle2, class: 'text-emerald-500', actionable: false },
    in_progress: {
        icon: Loader2,
        class: 'text-accent-blue animate-spin',
        actionable: false,
    },
    failed: { icon: AlertCircle, class: 'text-red-500', actionable: true },
    skipped: { icon: MinusCircle, class: 'text-ink-muted', actionable: false },
    pending: { icon: Circle, class: 'text-ink-muted', actionable: true },
};
</script>

<template>
    <div class="rounded-sp-sm border border-soft bg-surface p-3">
        <div class="flex items-center gap-2 text-xs font-medium text-ink">
            <ListChecks class="size-4 text-accent-blue" />
            <span class="truncate">{{
                plan.goal || t('apps.builder.plan.default_goal')
            }}</span>
            <span class="ml-auto shrink-0 text-ink-muted">
                {{ doneCount }}/{{ plan.steps.length }}
            </span>
        </div>

        <ol class="mt-2 space-y-1">
            <li v-for="step in plan.steps" :key="step.id">
                <button
                    type="button"
                    :disabled="!meta[step.status].actionable"
                    class="flex w-full items-start gap-2 rounded-sp-sm px-1.5 py-1 text-left text-xs transition-colors"
                    :class="
                        meta[step.status].actionable
                            ? 'cursor-pointer text-ink-muted hover:bg-navy hover:text-ink'
                            : 'cursor-default'
                    "
                    @click="
                        meta[step.status].actionable && emit('pick', step.title)
                    "
                >
                    <component
                        :is="meta[step.status].icon"
                        class="mt-0.5 size-3.5 shrink-0"
                        :class="meta[step.status].class"
                    />
                    <span
                        class="min-w-0 flex-1"
                        :class="{
                            'text-ink':
                                step.status === 'pending' ||
                                step.status === 'failed',
                            'line-through opacity-60':
                                step.status === 'done' ||
                                step.status === 'skipped',
                        }"
                    >
                        {{ step.title }}
                        <span
                            v-if="step.version_number"
                            class="ml-1 text-[10px] text-ink-muted"
                            >v{{ step.version_number }}</span
                        >
                    </span>
                </button>
            </li>
        </ol>
    </div>
</template>
