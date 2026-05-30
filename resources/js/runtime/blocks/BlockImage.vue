<script setup lang="ts">
import { computed } from 'vue';

interface ImageBlock {
    id: string;
    type: 'image';
    src: string;
    alt?: string;
    fit?: 'contain' | 'cover' | 'fill';
    rounded?: boolean;
    max_height?: number;
}

const props = defineProps<{ block: ImageBlock }>();

const fitClass = computed(() =>
    ({ contain: 'object-contain', cover: 'object-cover', fill: 'object-fill' }[props.block.fit ?? 'contain']),
);
const style = computed(() => (props.block.max_height ? `max-height: ${props.block.max_height}px` : 'max-height: 400px'));
</script>

<template>
    <img
        :src="block.src"
        :alt="block.alt ?? ''"
        :class="['w-full', fitClass, block.rounded ? 'rounded-sp-sm' : '']"
        :style="style"
        loading="lazy"
    />
</template>
