<script setup lang="ts">
import { computed, defineAsyncComponent, provide } from 'vue';
import type { AnyBlock, BlockData, ObjectDef, RuntimeTheme } from './types/manifest';
import { ThemeKey } from './useRuntimeTheme';

const BlockContainer = defineAsyncComponent(() => import('./blocks/BlockContainer.vue'));
const BlockText = defineAsyncComponent(() => import('./blocks/BlockText.vue'));
const BlockHeading = defineAsyncComponent(() => import('./blocks/BlockHeading.vue'));
const BlockDivider = defineAsyncComponent(() => import('./blocks/BlockDivider.vue'));
const BlockSpacer = defineAsyncComponent(() => import('./blocks/BlockSpacer.vue'));
const BlockTable = defineAsyncComponent(() => import('./blocks/BlockTable.vue'));
const BlockStat = defineAsyncComponent(() => import('./blocks/BlockStat.vue'));
const BlockForm = defineAsyncComponent(() => import('./blocks/BlockForm.vue'));
const BlockButton = defineAsyncComponent(() => import('./blocks/BlockButton.vue'));
const BlockModal = defineAsyncComponent(() => import('./blocks/BlockModal.vue'));
const BlockChart = defineAsyncComponent(() => import('./blocks/BlockChart.vue'));
const BlockKanban = defineAsyncComponent(() => import('./blocks/BlockKanban.vue'));
const BlockCalendar = defineAsyncComponent(() => import('./blocks/BlockCalendar.vue'));
const BlockMarkdown = defineAsyncComponent(() => import('./blocks/BlockMarkdown.vue'));
const BlockImage = defineAsyncComponent(() => import('./blocks/BlockImage.vue'));
const BlockMetricGrid = defineAsyncComponent(() => import('./blocks/BlockMetricGrid.vue'));
const BlockSparkline = defineAsyncComponent(() => import('./blocks/BlockSparkline.vue'));
const BlockGauge = defineAsyncComponent(() => import('./blocks/BlockGauge.vue'));
const BlockHeatmap = defineAsyncComponent(() => import('./blocks/BlockHeatmap.vue'));
const BlockTimeline = defineAsyncComponent(() => import('./blocks/BlockTimeline.vue'));
const BlockFunnel = defineAsyncComponent(() => import('./blocks/BlockFunnel.vue'));
const BlockMap = defineAsyncComponent(() => import('./blocks/BlockMap.vue'));
const BlockTabs = defineAsyncComponent(() => import('./blocks/BlockTabs.vue'));
const BlockAccordion = defineAsyncComponent(() => import('./blocks/BlockAccordion.vue'));
const BlockSplitView = defineAsyncComponent(() => import('./blocks/BlockSplitView.vue'));
const BlockCardGrid = defineAsyncComponent(() => import('./blocks/BlockCardGrid.vue'));
const BlockMultiStepForm = defineAsyncComponent(() => import('./blocks/BlockMultiStepForm.vue'));

const componentForType = {
    container: BlockContainer,
    text: BlockText,
    heading: BlockHeading,
    divider: BlockDivider,
    spacer: BlockSpacer,
    table: BlockTable,
    stat: BlockStat,
    form: BlockForm,
    button: BlockButton,
    modal: BlockModal,
    chart: BlockChart,
    kanban: BlockKanban,
    calendar: BlockCalendar,
    markdown: BlockMarkdown,
    image: BlockImage,
    metric_grid: BlockMetricGrid,
    sparkline: BlockSparkline,
    gauge: BlockGauge,
    heatmap: BlockHeatmap,
    timeline: BlockTimeline,
    funnel: BlockFunnel,
    map: BlockMap,
    tabs: BlockTabs,
    accordion: BlockAccordion,
    split_view: BlockSplitView,
    card_grid: BlockCardGrid,
    multi_step_form: BlockMultiStepForm,
} as const;

type SupportedType = keyof typeof componentForType;

const props = defineProps<{
    blocks: AnyBlock[];
    blockData: BlockData;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
    theme?: RuntimeTheme;
}>();

const theme = computed<RuntimeTheme>(() => props.theme ?? 'dark');

// Provide the theme to descendants. We provide a getter so changes propagate.
provide(ThemeKey, theme.value);

function isSupported(type: string): type is SupportedType {
    return type in componentForType;
}

function blockError(blockId: string): string | null {
    const entry = props.blockData[blockId];
    if (entry && typeof entry === 'object' && 'error' in entry && typeof (entry as { error?: unknown }).error === 'string') {
        return (entry as { error: string }).error;
    }
    return null;
}
</script>

<template>
    <template v-for="block in blocks" :key="block.id">
        <div
            v-if="blockError(block.id)"
            class="rounded-sp-sm border border-dashed border-amber-400/40 bg-amber-500/5 p-3 text-[11px] text-amber-400"
        >
            <div class="font-medium">Block "{{ block.id }}" ({{ block.type }}) could not load</div>
            <div class="mt-1 font-mono text-amber-300/70">{{ blockError(block.id) }}</div>
        </div>
        <component
            v-else-if="isSupported(block.type)"
            :is="componentForType[block.type as SupportedType]"
            :block="block"
            :block-data="blockData"
            :data="blockData[block.id]"
            :objects="objects"
            :locale="locale"
            :default-currency="defaultCurrency"
        />
        <div
            v-else
            class="rounded-sp-sm border border-dashed border-red-400/40 bg-red-500/5 p-3 text-[11px] text-red-400"
        >
            Unsupported block type: {{ block.type }}
        </div>
    </template>
</template>
