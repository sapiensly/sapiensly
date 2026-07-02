<script setup lang="ts">
import DeckSlide from '@/components/slides/DeckSlide.vue';
import { deckTheme, type DeckBrand, type DeckManifest } from '@/lib/deck';
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted } from 'vue';

/**
 * Print layout captured by headless Chrome for the PDF export: every slide
 * stacked at the exact 1280×720 design canvas, one per PDF page — the same
 * components as the live viewer, so the export is pixel-faithful.
 * Browsershot waits for `window.deckReady` before printing.
 */
const props = defineProps<{
    deck: { id: string; name: string; manifest: DeckManifest };
    brand: DeckBrand;
}>();

const slides = computed(() => props.deck.manifest.slides ?? []);
const tokens = computed(() =>
    deckTheme(props.deck.manifest.theme, props.brand.accent),
);

const stageStyle = computed(() => ({
    '--deck-bg': tokens.value.bg,
    '--deck-surface': tokens.value.surface,
    '--deck-ink': tokens.value.ink,
    '--deck-muted': tokens.value.muted,
    '--deck-subtle': tokens.value.subtle,
    '--deck-line': tokens.value.line,
    '--deck-accent': tokens.value.series[0],
}));

onMounted(async () => {
    await nextTick();
    // Charts render without animation in print mode; the small buffer covers
    // font loading and the canvas' first paint before Chrome snapshots.
    setTimeout(() => {
        (window as unknown as { deckReady: boolean }).deckReady = true;
    }, 500);
});
</script>

<template>
    <Head :title="deck.name" />

    <div class="print-root" :style="stageStyle">
        <div v-for="(slide, i) in slides" :key="i" class="sheet">
            <DeckSlide
                :slide="slide"
                :position="i + 1"
                :tokens="tokens"
                :logo-url="brand.logo_url"
                :print-mode="true"
            />
        </div>
    </div>
</template>

<style scoped>
.print-root {
    background: var(--deck-bg);
}
.sheet {
    position: relative;
    width: 1280px;
    height: 720px;
    overflow: hidden;
    background: var(--deck-bg);
    page-break-after: always;
    break-after: page;
}
</style>

<style>
/* One slide per PDF page, edge to edge. */
@page {
    size: 13.3333in 7.5in;
    margin: 0;
}
</style>
