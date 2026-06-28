<script setup lang="ts">
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';

type Variant =
    | 'insight'
    | 'recommendation'
    | 'conclusion'
    | 'positive'
    | 'warning'
    | 'risk';
interface InsightBlock {
    id: string;
    type: 'insight';
    variant?: Variant;
    title: string;
    body?: string;
    icon?: string;
    metric?: string;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: InsightBlock }>();

// Each variant carries an accent colour + default emoji. Colours are applied
// as a left border + tinted background derived from the accent, so the card
// reads on light or dark sections.
const VARIANTS: Record<
    Variant,
    { color: string; icon: string; label: string }
> = {
    insight: { color: '#3B82F6', icon: '💡', label: 'Insight' },
    recommendation: { color: '#8B5CF6', icon: '🎯', label: 'Recomendación' },
    conclusion: { color: '#0EA5E9', icon: '📌', label: 'Conclusión' },
    positive: { color: '#10B981', icon: '✅', label: 'Positivo' },
    warning: { color: '#F59E0B', icon: '⚠️', label: 'Atención' },
    risk: { color: '#EF4444', icon: '🚩', label: 'Riesgo' },
};

const v = computed(() => VARIANTS[props.block.variant ?? 'insight']);
</script>

<template>
    <div
        class="flex gap-4 rounded-xl border border-l-4 p-5"
        :style="{
            borderColor: 'color-mix(in srgb, currentColor 12%, transparent)',
            borderLeftColor: v.color,
            backgroundColor: `color-mix(in srgb, ${v.color} 7%, transparent)`,
        }"
    >
        <div><RuntimeIcon :name="block.icon || v.icon" :size="24" /></div>
        <div class="min-w-0 flex-1">
            <div
                class="text-[11px] font-semibold tracking-wider uppercase"
                :style="{ color: v.color }"
            >
                {{ v.label }}
            </div>
            <h3 class="mt-0.5 text-base leading-snug font-semibold">
                {{ block.title }}
            </h3>
            <p
                v-if="block.body"
                class="mt-1.5 text-sm leading-relaxed"
                :style="{ opacity: 0.8 }"
            >
                {{ block.body }}
            </p>
        </div>
        <div
            v-if="block.metric"
            class="shrink-0 self-center text-2xl font-bold tracking-tight"
            :style="{ color: v.color }"
        >
            {{ block.metric }}
        </div>
    </div>
</template>
