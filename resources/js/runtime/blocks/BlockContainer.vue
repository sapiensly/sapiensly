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
    // A row lays its children out as EQUAL columns that fill the width and
    // stretch to a shared height (items-stretch): `grow basis-72` makes every
    // child claim an equal share of the free space (no card left narrow with an
    // empty gutter beside it) while still wrapping to full width on a narrow
    // screen. Paired with cards whose content fills their height, this removes
    // both the horizontal gap (short cards) and the vertical gap (short content).
    const dir =
        props.block.direction === 'row'
            ? 'flex flex-row flex-wrap items-stretch [&>*]:min-w-0 [&>*]:grow [&>*]:basis-72'
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
