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
const BlockHero = defineAsyncComponent(() => import('./blocks/BlockHero.vue'));
const BlockFeatureGrid = defineAsyncComponent(() => import('./blocks/BlockFeatureGrid.vue'));
const BlockCta = defineAsyncComponent(() => import('./blocks/BlockCta.vue'));
const BlockStatBand = defineAsyncComponent(() => import('./blocks/BlockStatBand.vue'));
const BlockInsight = defineAsyncComponent(() => import('./blocks/BlockInsight.vue'));
const BlockWordCloud = defineAsyncComponent(() => import('./blocks/BlockWordCloud.vue'));
const BlockFlow = defineAsyncComponent(() => import('./blocks/BlockFlow.vue'));
const BlockTestimonials = defineAsyncComponent(() => import('./blocks/BlockTestimonials.vue'));
const BlockFaq = defineAsyncComponent(() => import('./blocks/BlockFaq.vue'));
const BlockPricing = defineAsyncComponent(() => import('./blocks/BlockPricing.vue'));

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
    hero: BlockHero,
    feature_grid: BlockFeatureGrid,
    cta: BlockCta,
    stat_band: BlockStatBand,
    insight: BlockInsight,
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
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
    theme?: RuntimeTheme;
}>();

const theme = computed<RuntimeTheme>(() => props.theme ?? 'light');

// Provide the theme to descendants. We provide a getter so changes propagate.
provide(ThemeKey, theme.value);

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
const PADDING: Record<string, string> = { none: '', sm: 'p-3', md: 'p-5', lg: 'px-6 py-10 sm:px-10 sm:py-14' };
const MARGIN: Record<string, string> = { none: '', sm: 'my-2', md: 'my-4', lg: 'my-8' };
const MAX_WIDTH: Record<string, string> = { sm: '640px', md: '820px', lg: '1100px', full: '' };

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

const GRADIENT_DIR: Record<string, string> = { 'to-b': 'to bottom', 'to-r': 'to right', 'to-br': 'to bottom right', 'to-tr': 'to top right' };

function hasWrapper(block: AnyBlock): boolean {
    const s = block.style;
    return (
        !!s &&
        (!!s.background ||
            !!s.color ||
            !!s.gradient ||
            !!s.full_bleed ||
            (!!s.padding && s.padding !== 'none') ||
            (!!s.margin && s.margin !== 'none') ||
            (!!s.max_width && s.max_width !== 'full'))
    );
}

function wrapperClass(block: AnyBlock): string {
    const s = block.style;
    if (!s) return '';
    const classes = [PADDING[s.padding ?? ''] ?? '', MARGIN[s.margin ?? ''] ?? ''];
    if (s.background || s.color || s.gradient) classes.push('sp-styled');
    if (s.full_bleed) classes.push('sp-bleed');
    return classes.filter(Boolean).join(' ');
}

function wrapperStyle(block: AnyBlock): Record<string, string> | undefined {
    const s = block.style;
    if (!s) return undefined;
    const out: Record<string, string> = {};

    // Background: a gradient (preferred when set) or a flat colour. The text
    // colour is auto-derived from the (average) background luminance so the
    // section stays legible on any page theme, unless explicitly set.
    let contrastFrom = s.background;
    if (s.gradient) {
        const dir = GRADIENT_DIR[s.gradient.direction ?? 'to-br'] ?? 'to bottom right';
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
    const p = (h: string) => [1, 3, 5].map((i) => parseInt(h.replace('#', '').slice(i - 1, i + 1), 16));
    const [r1, g1, b1] = p(a);
    const [r2, g2, b2] = p(b);
    const mid = (x: number, y: number) => Math.round((x + y) / 2).toString(16).padStart(2, '0');
    return `#${mid(r1, r2)}${mid(g1, g2)}${mid(b1, b2)}`;
}

// Centre and constrain the block's content to a readable width while the
// (optional) background stays full-bleed on the outer wrapper.
function innerMaxWidth(block: AnyBlock): string {
    const mw = block.style?.max_width;
    return mw && mw !== 'full' ? MAX_WIDTH[mw] ?? '' : '';
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
        <div
            v-else-if="isSupported(block.type) && hasWrapper(block)"
            :class="wrapperClass(block)"
            :style="wrapperStyle(block)"
        >
            <div :style="innerMaxWidth(block) ? { maxWidth: innerMaxWidth(block), marginInline: 'auto' } : undefined">
                <component
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
