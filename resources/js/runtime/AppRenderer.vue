<script setup lang="ts">
import { computed, defineAsyncComponent, provide, inject } from 'vue';
import type { ComputedRef } from 'vue';
import type {
    AnyBlock,
    BlockData,
    ObjectDef,
    RuntimeTheme,
} from './types/manifest';
import { ThemeKey } from './useRuntimeTheme';

const BlockContainer = defineAsyncComponent(
    () => import('./blocks/BlockContainer.vue'),
);
const BlockText = defineAsyncComponent(() => import('./blocks/BlockText.vue'));
const BlockHeading = defineAsyncComponent(
    () => import('./blocks/BlockHeading.vue'),
);
const BlockDivider = defineAsyncComponent(
    () => import('./blocks/BlockDivider.vue'),
);
const BlockSpacer = defineAsyncComponent(
    () => import('./blocks/BlockSpacer.vue'),
);
const BlockTable = defineAsyncComponent(
    () => import('./blocks/BlockTable.vue'),
);
const BlockRecordDetail = defineAsyncComponent(
    () => import('./blocks/BlockRecordDetail.vue'),
);
const BlockRelatedList = defineAsyncComponent(
    () => import('./blocks/BlockRelatedList.vue'),
);
const BlockFilterBar = defineAsyncComponent(
    () => import('./blocks/BlockFilterBar.vue'),
);
const BlockDataGrid = defineAsyncComponent(
    () => import('./blocks/BlockDataGrid.vue'),
);
const BlockStat = defineAsyncComponent(() => import('./blocks/BlockStat.vue'));
const BlockForm = defineAsyncComponent(() => import('./blocks/BlockForm.vue'));
const BlockButton = defineAsyncComponent(
    () => import('./blocks/BlockButton.vue'),
);
const BlockModal = defineAsyncComponent(
    () => import('./blocks/BlockModal.vue'),
);
const BlockChart = defineAsyncComponent(
    () => import('./blocks/BlockChart.vue'),
);
const BlockKanban = defineAsyncComponent(
    () => import('./blocks/BlockKanban.vue'),
);
const BlockCalendar = defineAsyncComponent(
    () => import('./blocks/BlockCalendar.vue'),
);
const BlockMarkdown = defineAsyncComponent(
    () => import('./blocks/BlockMarkdown.vue'),
);
const BlockImage = defineAsyncComponent(
    () => import('./blocks/BlockImage.vue'),
);
const BlockMetricGrid = defineAsyncComponent(
    () => import('./blocks/BlockMetricGrid.vue'),
);
const BlockSparkline = defineAsyncComponent(
    () => import('./blocks/BlockSparkline.vue'),
);
const BlockGauge = defineAsyncComponent(
    () => import('./blocks/BlockGauge.vue'),
);
const BlockProgress = defineAsyncComponent(
    () => import('./blocks/BlockProgress.vue'),
);
const BlockHeatmap = defineAsyncComponent(
    () => import('./blocks/BlockHeatmap.vue'),
);
const BlockTimeline = defineAsyncComponent(
    () => import('./blocks/BlockTimeline.vue'),
);
const BlockGantt = defineAsyncComponent(
    () => import('./blocks/BlockGantt.vue'),
);
const BlockFunnel = defineAsyncComponent(
    () => import('./blocks/BlockFunnel.vue'),
);
const BlockMap = defineAsyncComponent(() => import('./blocks/BlockMap.vue'));
const BlockTabs = defineAsyncComponent(() => import('./blocks/BlockTabs.vue'));
const BlockAccordion = defineAsyncComponent(
    () => import('./blocks/BlockAccordion.vue'),
);
const BlockSplitView = defineAsyncComponent(
    () => import('./blocks/BlockSplitView.vue'),
);
const BlockCardGrid = defineAsyncComponent(
    () => import('./blocks/BlockCardGrid.vue'),
);
const BlockMultiStepForm = defineAsyncComponent(
    () => import('./blocks/BlockMultiStepForm.vue'),
);
const BlockHero = defineAsyncComponent(() => import('./blocks/BlockHero.vue'));
const BlockFeatureGrid = defineAsyncComponent(
    () => import('./blocks/BlockFeatureGrid.vue'),
);
const BlockCta = defineAsyncComponent(() => import('./blocks/BlockCta.vue'));
const BlockStatBand = defineAsyncComponent(
    () => import('./blocks/BlockStatBand.vue'),
);
const BlockInsight = defineAsyncComponent(
    () => import('./blocks/BlockInsight.vue'),
);
const BlockAlert = defineAsyncComponent(
    () => import('./blocks/BlockAlert.vue'),
);
const BlockBadge = defineAsyncComponent(
    () => import('./blocks/BlockBadge.vue'),
);
const BlockStepper = defineAsyncComponent(
    () => import('./blocks/BlockStepper.vue'),
);
const BlockAvatar = defineAsyncComponent(
    () => import('./blocks/BlockAvatar.vue'),
);
const BlockBreadcrumb = defineAsyncComponent(
    () => import('./blocks/BlockBreadcrumb.vue'),
);
const BlockCarousel = defineAsyncComponent(
    () => import('./blocks/BlockCarousel.vue'),
);
const BlockWordCloud = defineAsyncComponent(
    () => import('./blocks/BlockWordCloud.vue'),
);
const BlockFlow = defineAsyncComponent(() => import('./blocks/BlockFlow.vue'));
const BlockTestimonials = defineAsyncComponent(
    () => import('./blocks/BlockTestimonials.vue'),
);
const BlockFaq = defineAsyncComponent(() => import('./blocks/BlockFaq.vue'));
const BlockPricing = defineAsyncComponent(
    () => import('./blocks/BlockPricing.vue'),
);

import BlockSkeleton from './blocks/BlockSkeleton.vue';

const componentForType = {
    container: BlockContainer,
    text: BlockText,
    heading: BlockHeading,
    divider: BlockDivider,
    spacer: BlockSpacer,
    table: BlockTable,
    data_grid: BlockDataGrid,
    record_detail: BlockRecordDetail,
    related_list: BlockRelatedList,
    filter_bar: BlockFilterBar,
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
    progress: BlockProgress,
    heatmap: BlockHeatmap,
    timeline: BlockTimeline,
    gantt: BlockGantt,
    funnel: BlockFunnel,
    map: BlockMap,
    tabs: BlockTabs,
    accordion: BlockAccordion,
    split_view: BlockSplitView,
    card_grid: BlockCardGrid,
    multi_step_form: BlockMultiStepForm,
    hero: BlockHero,
    feature_grid: BlockFeatureGrid,
    cta: BlockCta,
    stat_band: BlockStatBand,
    insight: BlockInsight,
    stepper: BlockStepper,
    badge: BlockBadge,
    alert: BlockAlert,
    avatar: BlockAvatar,
    breadcrumb: BlockBreadcrumb,
    carousel: BlockCarousel,
    word_cloud: BlockWordCloud,
    flow: BlockFlow,
    testimonials: BlockTestimonials,
    faq: BlockFaq,
    pricing: BlockPricing,
} as const;

type SupportedType = keyof typeof componentForType;

const props = defineProps<{
    blocks: AnyBlock[];
    blockData: BlockData;
    /** True while the page's deferred blockData is still loading. */
    loading?: boolean;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
    theme?: RuntimeTheme;
    /**
     * True when this renderer is nested inside a layout container (split_view,
     * modal, tabs, accordion, container). `full_bleed` is a PAGE-level escape
     * (break out of the page's content padding); inside a padded panel its
     * negative margin just pushes the block out of the panel — so we ignore it.
     */
    nested?: boolean;
}>();

const theme = computed<RuntimeTheme>(() => props.theme ?? 'light');

// Provide the theme to descendants. We provide a getter so changes propagate.
provide(ThemeKey, theme.value);

// Block types whose content is server-resolved data — these show a pulsing
// skeleton while the page's deferred blockData loads. Layout, text and the
// filter bar render immediately.
const DATA_BLOCK_TYPES = new Set([
    'chart',
    'table',
    'data_grid',
    'metric_grid',
    'stat',
    'gauge',
    'progress',
    'insight',
    'kanban',
    'calendar',
    'sparkline',
    'heatmap',
    'timeline',
    'gantt',
    'map',
    'card_grid',
    'word_cloud',
    'record_detail',
]);

const injectedLoading = inject<ComputedRef<boolean> | null>(
    'blockDataLoading',
    null,
);

function pendingData(block: AnyBlock): boolean {
    return (
        (props.loading === true || injectedLoading?.value === true) &&
        DATA_BLOCK_TYPES.has(block.type) &&
        props.blockData[block.id] === undefined
    );
}

function isSupported(type: string): type is SupportedType {
    return type in componentForType;
}

// Per-block style overrides (padding / margin / background). The schema allows
// these on every block; we apply them here as a thin wrapper so any block can
// become a coloured, padded section without per-component support.
// Per-block style overrides (padding / margin / background / color / max_width).
// The schema allows these on every block; we apply them here as a thin wrapper
// so any block becomes a coloured, padded, width-constrained section without
// per-component support.
const PADDING: Record<string, string> = {
    none: '',
    sm: 'p-3',
    md: 'p-5',
    lg: 'px-6 py-10 sm:px-10 sm:py-14',
};
const MARGIN: Record<string, string> = {
    none: '',
    sm: 'my-2',
    md: 'my-4',
    lg: 'my-8',
};
const MAX_WIDTH: Record<string, string> = {
    sm: '640px',
    md: '820px',
    lg: '1100px',
    full: '',
};

// Pick a readable text colour for a background hex from its relative luminance,
// so a section the author colours stays legible regardless of the page theme
// (light pastel → dark text, dark slate → light text). Overridden by an
// explicit style.color.
function readableText(hex: string): string {
    const c = hex.replace('#', '');
    if (c.length !== 6) return '';
    const r = parseInt(c.slice(0, 2), 16);
    const g = parseInt(c.slice(2, 4), 16);
    const b = parseInt(c.slice(4, 6), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.6 ? '#0f172a' : '#f8fafc';
}

const GRADIENT_DIR: Record<string, string> = {
    'to-b': 'to bottom',
    'to-r': 'to right',
    'to-br': 'to bottom right',
    'to-tr': 'to top right',
};

// A hero paints its OWN background (rounded, with its grid/highlight) inside
// BlockHero — so the generic wrapper must never repaint the gradient/colour on
// a square, un-rounded div behind it (its corners bled past the hero's radius).
function selfPaintsBackground(block: AnyBlock): boolean {
    return block.type === 'hero';
}

function hasWrapper(block: AnyBlock): boolean {
    const s = block.style;
    if (!s) return false;
    const bg = !selfPaintsBackground(block) && (!!s.background || !!s.color || !!s.gradient);
    return (
        bg ||
        !!s.full_bleed ||
        (!!s.padding && s.padding !== 'none') ||
        (!!s.margin && s.margin !== 'none') ||
        (!!s.max_width && s.max_width !== 'full')
    );
}

function wrapperClass(block: AnyBlock): string {
    const s = block.style;
    if (!s) return '';
    const classes = [
        PADDING[s.padding ?? ''] ?? '',
        MARGIN[s.margin ?? ''] ?? '',
    ];
    if (!selfPaintsBackground(block) && (s.background || s.color || s.gradient)) {
        classes.push('sp-styled');
    }
    // full_bleed only makes sense at page level; inside a panel it overflows.
    if (s.full_bleed && !props.nested) classes.push('sp-bleed');
    return classes.filter(Boolean).join(' ');
}

function wrapperStyle(block: AnyBlock): Record<string, string> | undefined {
    const s = block.style;
    if (!s || selfPaintsBackground(block)) return undefined; // hero paints its own bg
    const out: Record<string, string> = {};

    // Background: a gradient (preferred when set) or a flat colour. The text
    // colour is auto-derived from the (average) background luminance so the
    // section stays legible on any page theme, unless explicitly set.
    let contrastFrom = s.background;
    if (s.gradient) {
        const dir =
            GRADIENT_DIR[s.gradient.direction ?? 'to-br'] ?? 'to bottom right';
        out.backgroundImage = `linear-gradient(${dir}, ${s.gradient.from}, ${s.gradient.to})`;
        contrastFrom = averageHex(s.gradient.from, s.gradient.to);
    } else if (s.background) {
        out.backgroundColor = s.background;
    }

    const color = s.color || (contrastFrom ? readableText(contrastFrom) : '');
    if (color) out.color = color;

    return Object.keys(out).length ? out : undefined;
}

function averageHex(a: string, b: string): string {
    const p = (h: string) =>
        [1, 3, 5].map((i) =>
            parseInt(h.replace('#', '').slice(i - 1, i + 1), 16),
        );
    const [r1, g1, b1] = p(a);
    const [r2, g2, b2] = p(b);
    const mid = (x: number, y: number) =>
        Math.round((x + y) / 2)
            .toString(16)
            .padStart(2, '0');
    return `#${mid(r1, r2)}${mid(g1, g2)}${mid(b1, b2)}`;
}

// Centre and constrain the block's content to a readable width while the
// (optional) background stays full-bleed on the outer wrapper.
function innerMaxWidth(block: AnyBlock): string {
    const mw = block.style?.max_width;
    return mw && mw !== 'full' ? (MAX_WIDTH[mw] ?? '') : '';
}

// Relative column width for a row child: turn `style.col_span` into a flex
// weight (flex-grow N, basis 0) so a row splits its width by the children's
// weights — e.g. a chart with col_span 7 beside one with col_span 3 → 70/30 —
// while the row's items-stretch keeps both the SAME height. Overrides the row
// container's default equal-column classes. No-op outside a flex row.
function colSpanStyle(block: AnyBlock): Record<string, string> | undefined {
    const span = block.style?.col_span;
    const minHeight = (block.style as { min_height?: number } | undefined)
        ?.min_height;
    const out: Record<string, string> = {};
    if (span) {
        out.flexGrow = String(span);
        out.flexBasis = '0%';
        out.minWidth = '0';
        // The cap makes the span REAL for a lone card too: flex-grow only
        // splits free space, so a single child always filled its row and
        // could never be narrowed to make room for a neighbour.
        out.maxWidth = `${((span / 12) * 100).toFixed(3)}%`;
    }
    if (minHeight) out.minHeight = `${minHeight}px`;
    return Object.keys(out).length ? out : undefined;
}

function outerStyle(block: AnyBlock): Record<string, string> | undefined {
    const merged = { ...wrapperStyle(block), ...colSpanStyle(block) };
    return Object.keys(merged).length ? merged : undefined;
}

function blockError(blockId: string): string | null {
    const entry = props.blockData[blockId];
    if (
        entry &&
        typeof entry === 'object' &&
        'error' in entry &&
        typeof (entry as { error?: unknown }).error === 'string'
    ) {
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
            <div class="font-medium">
                Block "{{ block.id }}" ({{ block.type }}) could not load
            </div>
            <div class="mt-1 font-mono text-amber-300/70">
                {{ blockError(block.id) }}
            </div>
        </div>
        <div
            v-else-if="isSupported(block.type) && hasWrapper(block)"
            :class="wrapperClass(block)"
            :style="outerStyle(block)"
            :data-block-id="block.id"
            :data-block-type="block.type"
            :data-block-direction="(block as any).direction"
        >
            <div
                :style="
                    innerMaxWidth(block)
                        ? {
                              maxWidth: innerMaxWidth(block),
                              marginInline: 'auto',
                          }
                        : undefined
                "
            >
                <BlockSkeleton v-if="pendingData(block)" :block="block" />
                <component
                    v-else
                    :is="componentForType[block.type as SupportedType]"
                    :block="block"
                    :block-data="blockData"
                    :data="blockData[block.id]"
                    :objects="objects"
                    :locale="locale"
                    :default-currency="defaultCurrency"
                />
            </div>
        </div>
        <BlockSkeleton
            v-else-if="isSupported(block.type) && pendingData(block)"
            :block="block"
            :style="colSpanStyle(block)"
        />
        <component
            v-else-if="isSupported(block.type)"
            :is="componentForType[block.type as SupportedType]"
            :block="block"
            :block-data="blockData"
            :data="blockData[block.id]"
            :objects="objects"
            :locale="locale"
            :default-currency="defaultCurrency"
            :style="colSpanStyle(block)"
            :data-block-id="block.id"
            :data-block-type="block.type"
            :data-block-direction="(block as any).direction"
        />
        <div
            v-else
            class="rounded-sp-sm border border-dashed border-red-400/40 bg-red-500/5 p-3 text-[11px] text-red-400"
        >
            Unsupported block type: {{ block.type }}
        </div>
    </template>
</template>
