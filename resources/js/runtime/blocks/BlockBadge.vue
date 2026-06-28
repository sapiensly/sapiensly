<script setup lang="ts">
import { computed } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';

type Variant = 'neutral' | 'accent' | 'info' | 'success' | 'warning' | 'danger';
interface BadgeBlock {
    id: string;
    type: 'badge';
    label: string;
    variant?: Variant;
    color?: string;
    icon?: string;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: BadgeBlock }>();

const VARIANT_COLOR: Record<Variant, string> = {
    neutral: '#64748b',
    accent: 'var(--sp-accent, #3b82f6)',
    info: '#3B82F6',
    success: '#10B981',
    warning: '#F59E0B',
    danger: '#EF4444',
};

// A custom hex wins; otherwise the variant preset. The chip is a tinted
// background + the colour as text, so it reads on light or dark surfaces.
const color = computed(
    () => props.block.color || VARIANT_COLOR[props.block.variant ?? 'neutral'],
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-pill px-2.5 py-0.5 text-xs font-medium"
        :style="{
            color,
            backgroundColor: `color-mix(in srgb, ${color} 14%, transparent)`,
        }"
    >
        <RuntimeIcon v-if="block.icon" :name="block.icon" :size="12" />
        {{ block.label }}
    </span>
</template>
