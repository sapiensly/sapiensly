<script setup lang="ts">
import type { DeckSlideDef, DeckThemeTokens } from '@/lib/deck';
import SlideBigNumber from './layouts/SlideBigNumber.vue';
import SlideBullets from './layouts/SlideBullets.vue';
import SlideChart from './layouts/SlideChart.vue';
import SlideClosing from './layouts/SlideClosing.vue';
import SlideMetrics from './layouts/SlideMetrics.vue';
import SlideQuote from './layouts/SlideQuote.vue';
import SlideRoadmap from './layouts/SlideRoadmap.vue';
import SlideSection from './layouts/SlideSection.vue';
import SlideTable from './layouts/SlideTable.vue';
import SlideTimeline from './layouts/SlideTimeline.vue';
import SlideTitle from './layouts/SlideTitle.vue';
import SlideTwoColumn from './layouts/SlideTwoColumn.vue';

/**
 * Renders one slide of a validated deck manifest by dispatching to the layout
 * component. The manifest is server-validated (DeckValidator), so fields are
 * trusted here; `s` casts keep the dispatcher thin.
 */
const props = defineProps<{
    slide: DeckSlideDef;
    /** 1-based position in the deck (section slides show it as the index). */
    position: number;
    tokens: DeckThemeTokens;
    logoUrl?: string | null;
    /** true in the PDF print page: disables chart animation. */
    printMode?: boolean;
}>();

const s = props.slide as Record<string, never>;
</script>

<template>
    <SlideTitle
        v-if="slide.layout === 'title'"
        :title="s.title"
        :subtitle="s.subtitle"
        :meta="s.meta"
        :logo-url="logoUrl"
    />
    <SlideSection
        v-else-if="slide.layout === 'section'"
        :title="s.title"
        :kicker="s.kicker"
        :index="position"
    />
    <SlideBullets
        v-else-if="slide.layout === 'bullets'"
        :title="s.title"
        :kicker="s.kicker"
        :bullets="s.bullets"
    />
    <SlideTwoColumn
        v-else-if="slide.layout === 'two_column'"
        :title="s.title"
        :left="s.left"
        :right="s.right"
    />
    <SlideBigNumber
        v-else-if="slide.layout === 'big_number'"
        :value="s.value"
        :label="s.label"
        :kicker="s.kicker"
        :delta="s.delta"
        :context="s.context"
    />
    <SlideMetrics
        v-else-if="slide.layout === 'metrics'"
        :title="s.title"
        :items="s.items"
    />
    <SlideChart
        v-else-if="slide.layout === 'chart'"
        :title="s.title"
        :chart-type="s.chart_type"
        :labels="s.labels"
        :series="s.series"
        :takeaway="s.takeaway"
        :tokens="tokens"
        :animated="!printMode"
    />
    <SlideQuote
        v-else-if="slide.layout === 'quote'"
        :quote="s.quote"
        :attribution="s.attribution"
        :role="s.role"
    />
    <SlideTimeline
        v-else-if="slide.layout === 'timeline'"
        :title="s.title"
        :kicker="s.kicker"
        :items="s.items"
    />
    <SlideRoadmap
        v-else-if="slide.layout === 'roadmap'"
        :title="s.title"
        :kicker="s.kicker"
        :periods="s.periods"
        :lanes="s.lanes"
    />
    <SlideTable
        v-else-if="slide.layout === 'table'"
        :title="s.title"
        :columns="s.columns"
        :rows="s.rows"
    />
    <SlideClosing
        v-else-if="slide.layout === 'closing'"
        :title="s.title"
        :subtitle="s.subtitle"
        :bullets="s.bullets"
        :cta="s.cta"
        :logo-url="logoUrl"
    />
</template>
