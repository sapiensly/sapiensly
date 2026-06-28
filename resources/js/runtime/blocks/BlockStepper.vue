<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';

interface Step {
    id?: string;
    label: string;
    description?: string;
}
interface StepperBlock {
    id: string;
    type: 'stepper';
    steps: Step[];
    current_step?: number;
    orientation?: 'horizontal' | 'vertical';
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: StepperBlock }>();

const current = computed(() => props.block.current_step ?? 0);
const isVertical = computed(() => props.block.orientation === 'vertical');

function state(i: number): 'done' | 'active' | 'upcoming' {
    if (i < current.value) return 'done';
    if (i === current.value) return 'active';
    return 'upcoming';
}
</script>

<template>
    <ol
        :class="
            isVertical
                ? 'flex flex-col gap-0'
                : 'flex items-start gap-0 overflow-x-auto'
        "
    >
        <li
            v-for="(step, i) in block.steps"
            :key="step.id ?? i"
            :class="[
                'relative flex',
                isVertical
                    ? 'gap-3 pb-6 last:pb-0'
                    : 'flex-1 flex-col items-center text-center',
            ]"
        >
            <!-- Connector line to the next step. -->
            <span
                v-if="i < block.steps.length - 1"
                :class="
                    isVertical
                        ? 'absolute top-7 left-3.5 h-full w-px -translate-x-1/2'
                        : 'absolute top-3.5 left-1/2 h-px w-full'
                "
                :style="{
                    background:
                        state(i) === 'done'
                            ? 'var(--sp-accent, #3b82f6)'
                            : 'color-mix(in srgb, currentColor 15%, transparent)',
                }"
            />

            <span
                class="relative z-10 grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold"
                :style="
                    state(i) === 'upcoming'
                        ? {
                              border: '1px solid color-mix(in srgb, currentColor 30%, transparent)',
                              opacity: 0.6,
                          }
                        : {
                              background: 'var(--sp-accent, #3b82f6)',
                              color: 'var(--sp-accent-contrast, #fff)',
                          }
                "
            >
                <Check v-if="state(i) === 'done'" class="size-4" />
                <template v-else>{{ i + 1 }}</template>
            </span>

            <div :class="isVertical ? 'pt-0.5' : 'mt-2 px-1'">
                <div
                    class="text-sm font-medium"
                    :style="{ opacity: state(i) === 'upcoming' ? 0.6 : 1 }"
                >
                    {{ step.label }}
                </div>
                <div
                    v-if="step.description"
                    class="mt-0.5 text-xs"
                    :style="{ opacity: 0.6 }"
                >
                    {{ step.description }}
                </div>
            </div>
        </li>
    </ol>
</template>
