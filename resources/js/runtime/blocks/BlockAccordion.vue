<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import type { AnyBlock, BlockData, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import AppRenderer from '../AppRenderer.vue';

interface Section {
    id: string;
    title: string;
    default_open?: boolean;
    blocks: AnyBlock[];
}

interface AccordionBlock {
    id: string;
    type: 'accordion';
    allow_multiple?: boolean;
    sections: Section[];
}

const props = defineProps<{
    block: AccordionBlock;
    blockData: BlockData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

// Seed open state from `default_open`. Track in a ref so toggling re-renders.
const open = ref<Record<string, boolean>>(
    Object.fromEntries(props.block.sections.map((s) => [s.id, !!s.default_open])),
);

function toggle(id: string) {
    if (props.block.allow_multiple) {
        open.value = { ...open.value, [id]: ! open.value[id] };
        return;
    }
    const isOpen = open.value[id];
    const next: Record<string, boolean> = {};
    for (const s of props.block.sections) next[s.id] = false;
    next[id] = ! isOpen;
    open.value = next;
}
</script>

<template>
    <div :class="['divide-y divide-soft overflow-hidden rounded-sp-sm border', t.surface]">
        <section v-for="section in block.sections" :key="section.id">
            <button
                type="button"
                @click="toggle(section.id)"
                :class="['flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-surface', t.text]"
                :aria-expanded="!!open[section.id]"
            >
                <span class="text-sm font-medium">{{ section.title }}</span>
                <ChevronDown
                    class="size-4 transition-transform"
                    :class="[t.textMuted, open[section.id] ? 'rotate-180' : '']"
                />
            </button>
            <div v-if="open[section.id]" class="space-y-3 border-t border-soft px-4 py-4">
                <AppRenderer
                    :blocks="section.blocks"
                    :block-data="blockData"
                    :objects="objects"
                    :locale="locale"
                    :default-currency="defaultCurrency"
                />
            </div>
        </section>
    </div>
</template>
