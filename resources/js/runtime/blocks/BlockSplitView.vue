<script setup lang="ts">
import { computed } from 'vue';
import AppRenderer from '../AppRenderer.vue';
import type { AnyBlock, BlockData, ObjectDef } from '../types/manifest';

interface SplitViewBlock {
    id: string;
    type: 'split_view';
    left_blocks: AnyBlock[];
    right_blocks: AnyBlock[];
    left_fraction?: number; // 1-11
}

const props = defineProps<{
    block: SplitViewBlock;
    blockData: BlockData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const gridClass = computed(() => {
    const left = Math.max(1, Math.min(11, props.block.left_fraction ?? 4));
    const right = 12 - left;
    // Tailwind needs literal strings — switch on common splits and fall back
    // to inline style for arbitrary ratios.
    const known: Record<string, string> = {
        '3-9': 'md:grid-cols-[3fr_9fr]',
        '4-8': 'md:grid-cols-[4fr_8fr]',
        '5-7': 'md:grid-cols-[5fr_7fr]',
        '6-6': 'md:grid-cols-[6fr_6fr]',
        '7-5': 'md:grid-cols-[7fr_5fr]',
        '8-4': 'md:grid-cols-[8fr_4fr]',
        '9-3': 'md:grid-cols-[9fr_3fr]',
    };
    return known[`${left}-${right}`] ?? '';
});

const fallbackStyle = computed(() => {
    const left = Math.max(1, Math.min(11, props.block.left_fraction ?? 4));
    const right = 12 - left;
    return gridClass.value === ''
        ? `grid-template-columns: ${left}fr ${right}fr`
        : '';
});
</script>

<template>
    <div :class="['grid grid-cols-1 gap-4', gridClass]" :style="fallbackStyle">
        <div class="min-w-0 space-y-3">
            <AppRenderer
                :blocks="block.left_blocks"
                :block-data="blockData"
                :objects="objects"
                :locale="locale"
                :default-currency="defaultCurrency"
                :nested="true"
            />
        </div>
        <div class="min-w-0 space-y-3">
            <AppRenderer
                :blocks="block.right_blocks"
                :block-data="blockData"
                :objects="objects"
                :locale="locale"
                :default-currency="defaultCurrency"
                :nested="true"
            />
        </div>
    </div>
</template>
