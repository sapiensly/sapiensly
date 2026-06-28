<script setup lang="ts">
import { getInitials } from '@/composables/useInitials';
import { computed } from 'vue';

interface AvatarBlock {
    id: string;
    type: 'avatar';
    src?: string;
    name?: string;
    label?: string;
    caption?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    shape?: 'circle' | 'square';
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: AvatarBlock }>();

const SIZE_PX: Record<string, number> = { sm: 32, md: 44, lg: 64, xl: 96 };
const px = computed(() => SIZE_PX[props.block.size ?? 'md']);
const radius = computed(() =>
    props.block.shape === 'square' ? '14%' : '9999px',
);
const initials = computed(() => getInitials(props.block.name ?? '') || '•');
</script>

<template>
    <div class="flex items-center gap-3">
        <img
            v-if="block.src"
            :src="block.src"
            :alt="block.name ?? ''"
            class="shrink-0 object-cover"
            :style="{
                width: px + 'px',
                height: px + 'px',
                borderRadius: radius,
            }"
        />
        <span
            v-else
            class="grid shrink-0 place-items-center font-semibold text-white"
            :style="{
                width: px + 'px',
                height: px + 'px',
                borderRadius: radius,
                fontSize: Math.round(px * 0.4) + 'px',
                background: 'var(--sp-accent, #3b82f6)',
            }"
            >{{ initials }}</span
        >
        <div v-if="block.label || block.caption" class="min-w-0">
            <div v-if="block.label" class="truncate text-sm font-semibold">
                {{ block.label }}
            </div>
            <div
                v-if="block.caption"
                class="truncate text-xs"
                :style="{ opacity: 0.65 }"
            >
                {{ block.caption }}
            </div>
        </div>
    </div>
</template>
