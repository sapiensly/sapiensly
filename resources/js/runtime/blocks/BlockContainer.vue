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
    const gapKey = props.block.gap ?? 'md';

    // MASONRY: pack children into responsive CSS columns at their NATURAL height,
    // flowing top-to-bottom so nothing is stretched and no vertical gap is left —
    // a true masonry wall for independent cards of varying height. `gap` sets the
    // column gutter; a matching bottom margin + break-inside-avoid spaces the
    // stacked cards without splitting one across columns. (Reading order is
    // column-major, so it's for INDEPENDENT cards, not an ordered sequence.)
    if (props.block.direction === 'masonry') {
        const colGap = { none: 'gap-0', sm: 'gap-2', md: 'gap-4', lg: 'gap-8' }[
            gapKey
        ];
        const stackGap = {
            none: '[&>*]:mb-0',
            sm: '[&>*]:mb-2',
            md: '[&>*]:mb-4',
            lg: '[&>*]:mb-8',
        }[gapKey];
        return `columns-1 sm:columns-2 xl:columns-3 ${colGap} ${stackGap} [&>*]:break-inside-avoid`;
    }

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
        gapKey
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
