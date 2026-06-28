<script setup lang="ts">
import { ArrowDown, ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';

interface Step {
    id?: string;
    label: string;
    description?: string;
    icon?: string;
}
interface FlowBlock {
    id: string;
    type: 'flow';
    label?: string;
    direction?: 'row' | 'column';
    steps: Step[];
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: FlowBlock }>();

const isRow = computed(() => (props.block.direction ?? 'row') === 'row');
</script>

<template>
    <div class="flex flex-col gap-3">
        <p
            v-if="block.label"
            class="text-[11px] font-semibold tracking-wider uppercase"
            :style="{ opacity: 0.6 }"
        >
            {{ block.label }}
        </p>
        <div
            :class="[
                'flex',
                isRow
                    ? 'flex-col items-stretch gap-3 md:flex-row md:items-stretch'
                    : 'flex-col gap-3',
            ]"
        >
            <template v-for="(step, i) in block.steps" :key="step.id ?? i">
                <div
                    class="flex flex-1 flex-col gap-1 rounded-xl border p-4"
                    :style="{
                        borderColor:
                            'color-mix(in srgb, currentColor 14%, transparent)',
                        backgroundColor:
                            'color-mix(in srgb, currentColor 4%, transparent)',
                    }"
                >
                    <div class="flex items-center gap-2">
                        <RuntimeIcon
                            v-if="step.icon"
                            :name="step.icon"
                            :size="18"
                        />
                        <span
                            class="flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                            :style="{
                                backgroundColor: 'var(--sp-accent, #3b82f6)',
                            }"
                            >{{ i + 1 }}</span
                        >
                        <span class="text-sm font-semibold">{{
                            step.label
                        }}</span>
                    </div>
                    <p
                        v-if="step.description"
                        class="text-xs"
                        :style="{ opacity: 0.7 }"
                    >
                        {{ step.description }}
                    </p>
                </div>
                <div
                    v-if="i < block.steps.length - 1"
                    class="flex items-center justify-center self-center"
                    :style="{ color: 'var(--sp-accent, #3b82f6)' }"
                >
                    <ArrowRight v-if="isRow" class="hidden size-5 md:block" />
                    <ArrowDown v-if="isRow" class="size-5 md:hidden" />
                    <ArrowDown v-else class="size-5" />
                </div>
            </template>
        </div>
    </div>
</template>
