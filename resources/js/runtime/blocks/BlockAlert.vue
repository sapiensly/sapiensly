<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';

type Variant = 'info' | 'success' | 'warning' | 'danger';
interface AlertBlock {
    id: string;
    type: 'alert';
    variant?: Variant;
    title: string;
    body?: string;
    icon?: string;
    dismissible?: boolean;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: AlertBlock }>();

// Each variant carries a colour + default icon. The colour is applied as a left
// border + tinted background so the callout reads on light or dark sections.
const VARIANTS: Record<Variant, { color: string; icon: string }> = {
    info: { color: '#3B82F6', icon: 'info' },
    success: { color: '#10B981', icon: 'check-circle' },
    warning: { color: '#F59E0B', icon: 'alert-triangle' },
    danger: { color: '#EF4444', icon: 'alert-circle' },
};

const v = computed(() => VARIANTS[props.block.variant ?? 'info']);
const visible = ref(true);
</script>

<template>
    <div
        v-if="visible"
        class="flex items-start gap-3 rounded-xl border border-l-4 p-4"
        :style="{
            borderColor: 'color-mix(in srgb, currentColor 12%, transparent)',
            borderLeftColor: v.color,
            backgroundColor: `color-mix(in srgb, ${v.color} 8%, transparent)`,
        }"
    >
        <div :style="{ color: v.color }">
            <RuntimeIcon :name="block.icon || v.icon" :size="20" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="text-sm font-semibold">{{ block.title }}</div>
            <p v-if="block.body" class="mt-1 text-sm" :style="{ opacity: 0.8 }">
                {{ block.body }}
            </p>
        </div>
        <button
            v-if="block.dismissible"
            type="button"
            class="shrink-0 rounded-md p-0.5 opacity-50 transition-opacity hover:opacity-100"
            :aria-label="'Dismiss'"
            @click="visible = false"
        >
            <X class="size-4" />
        </button>
    </div>
</template>
