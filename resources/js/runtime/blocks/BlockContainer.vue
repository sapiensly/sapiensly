<script setup lang="ts">
import { computed } from 'vue';
import AppRenderer from '../AppRenderer.vue';
import type { BlockContainer, BlockData, ObjectDef } from '../types/manifest';

const props = defineProps<{
    block: BlockContainer;
    blockData: BlockData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const layoutClass = computed(() => {
    const dir =
        props.block.direction === 'row'
            ? 'flex flex-row flex-wrap'
            : 'flex flex-col';
    const gap = { none: 'gap-0', sm: 'gap-2', md: 'gap-4', lg: 'gap-8' }[
        props.block.gap ?? 'md'
    ];
    return `${dir} ${gap}`;
});
</script>

<template>
    <div :class="layoutClass">
        <AppRenderer
            :blocks="block.blocks"
            :block-data="blockData"
            :objects="objects"
            :locale="locale"
            :default-currency="defaultCurrency"
            :nested="true"
        />
    </div>
</template>
