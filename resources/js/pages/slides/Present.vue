<script setup lang="ts">
import * as SlidesController from '@/actions/App/Http/Controllers/SlidesController';
import DeckSlide from '@/components/slides/DeckSlide.vue';
import { deckTheme, type DeckBrand, type DeckManifest } from '@/lib/deck';
import { Head, router } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Download,
    Link2,
    X,
} from '@lucide/vue';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Full-screen deck viewer. The slide is laid out on a fixed 1280×720 design
 * canvas and scaled to fit the viewport, so typography and spacing are
 * IDENTICAL on every screen — the deterministic rendering that keeps decks
 * consistent (and, later, makes the PDF export pixel-faithful).
 */
const props = defineProps<{
    deck: { id: string; name: string; manifest: DeckManifest };
    brand: DeckBrand;
    /** true when opened through a share link: hides workspace affordances. */
    shared?: boolean;
}>();

const { t } = useI18n();

const DESIGN_W = 1280;
const DESIGN_H = 720;

const slides = computed(() => props.deck.manifest.slides ?? []);
const tokens = computed(() =>
    deckTheme(props.deck.manifest.theme, props.brand.accent),
);

const current = ref(0);
const direction = ref<'next' | 'prev'>('next');

function go(to: number) {
    const clamped = Math.max(0, Math.min(slides.value.length - 1, to));
    if (clamped === current.value) return;
    direction.value = clamped > current.value ? 'next' : 'prev';
    current.value = clamped;
}

function onKeydown(e: KeyboardEvent) {
    switch (e.key) {
        case 'ArrowRight':
        case 'ArrowDown':
        case ' ':
        case 'PageDown':
            e.preventDefault();
            go(current.value + 1);
            break;
        case 'ArrowLeft':
        case 'ArrowUp':
        case 'PageUp':
            e.preventDefault();
            go(current.value - 1);
            break;
        case 'Home':
            e.preventDefault();
            go(0);
            break;
        case 'End':
            e.preventDefault();
            go(slides.value.length - 1);
            break;
        case 'Escape':
            e.preventDefault();
            exit();
            break;
    }
}

function exit() {
    if (props.shared) return;
    router.visit(SlidesController.index().url);
}

// Share: mint (or refresh) the 30-day signed link and copy it.
const shareCopied = ref(false);
async function shareDeck() {
    try {
        const { data } = await axios.post(
            SlidesController.share(props.deck.id).url,
        );
        await navigator.clipboard.writeText(data.url);
        shareCopied.value = true;
        setTimeout(() => (shareCopied.value = false), 2000);
    } catch {
        // Clipboard or network hiccup — the button simply stays idle.
    }
}

const exportUrl = computed(() => SlidesController.export(props.deck.id).url);

// Scale the fixed design canvas to the viewport (letterboxed).
const scale = ref(1);
function rescale() {
    scale.value = Math.min(
        window.innerWidth / DESIGN_W,
        window.innerHeight / DESIGN_H,
    );
}

onMounted(() => {
    rescale();
    window.addEventListener('resize', rescale);
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', rescale);
    window.removeEventListener('keydown', onKeydown);
});

const stageStyle = computed(() => ({
    '--deck-bg': tokens.value.bg,
    '--deck-surface': tokens.value.surface,
    '--deck-ink': tokens.value.ink,
    '--deck-muted': tokens.value.muted,
    '--deck-subtle': tokens.value.subtle,
    '--deck-line': tokens.value.line,
    '--deck-accent': tokens.value.series[0],
}));

const progress = computed(() =>
    slides.value.length > 1
        ? (current.value / (slides.value.length - 1)) * 100
        : 100,
);
</script>

<template>
    <Head :title="deck.name" />

    <div class="viewer" :style="stageStyle">
        <!-- Progress -->
        <div class="progress">
            <div class="progress-fill" :style="{ width: `${progress}%` }" />
        </div>

        <!-- Stage: fixed canvas, scaled to fit -->
        <div class="stage-wrap" @click="go(current + 1)">
            <div
                class="stage"
                :style="{
                    width: `${DESIGN_W}px`,
                    height: `${DESIGN_H}px`,
                    transform: `translate(-50%, -50%) scale(${scale})`,
                }"
            >
                <Transition
                    :name="direction === 'next' ? 'slide-next' : 'slide-prev'"
                    mode="out-in"
                >
                    <div :key="current" class="slide">
                        <DeckSlide
                            :slide="slides[current]"
                            :position="current + 1"
                            :tokens="tokens"
                            :logo-url="brand.logo_url"
                        />
                    </div>
                </Transition>
            </div>
        </div>

        <!-- Controls -->
        <div v-if="!shared" class="toolbar">
            <button
                type="button"
                class="control"
                :aria-label="t('slides.present.share')"
                :title="t('slides.present.share')"
                @click.stop="shareDeck"
            >
                <Check v-if="shareCopied" class="icon" />
                <Link2 v-else class="icon" />
            </button>
            <a
                :href="exportUrl"
                class="control"
                :aria-label="t('slides.present.download_pdf')"
                :title="t('slides.present.download_pdf')"
                @click.stop
            >
                <Download class="icon" />
            </a>
            <button
                type="button"
                class="control"
                :aria-label="t('slides.present.close')"
                @click.stop="exit"
            >
                <X class="icon" />
            </button>
        </div>
        <button
            v-if="current > 0"
            type="button"
            class="control prev"
            aria-label="Previous"
            @click.stop="go(current - 1)"
        >
            <ChevronLeft class="icon" />
        </button>
        <button
            v-if="current < slides.length - 1"
            type="button"
            class="control next"
            aria-label="Next"
            @click.stop="go(current + 1)"
        >
            <ChevronRight class="icon" />
        </button>
        <div class="counter">{{ current + 1 }} / {{ slides.length }}</div>
    </div>
</template>

<style scoped>
.viewer {
    position: fixed;
    inset: 0;
    background: var(--deck-bg);
    overflow: hidden;
    user-select: none;
}
.progress {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: color-mix(in srgb, var(--deck-ink) 8%, transparent);
    z-index: 30;
}
.progress-fill {
    height: 100%;
    background: var(--deck-accent);
    transition: width 0.25s ease;
}
.stage-wrap {
    position: absolute;
    inset: 0;
    cursor: default;
}
.stage {
    position: absolute;
    top: 50%;
    left: 50%;
    transform-origin: center;
    background: var(--deck-bg);
}
.slide {
    position: absolute;
    inset: 0;
}

.control {
    position: absolute;
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--deck-ink) 7%, transparent);
    color: var(--deck-muted);
    transition:
        background 0.15s ease,
        color 0.15s ease,
        opacity 0.15s ease;
    opacity: 0.45;
}
.control:hover {
    opacity: 1;
    background: color-mix(in srgb, var(--deck-ink) 14%, transparent);
    color: var(--deck-ink);
}
.icon {
    width: 20px;
    height: 20px;
}
.toolbar {
    position: absolute;
    top: 20px;
    right: 20px;
    z-index: 40;
    display: flex;
    gap: 10px;
}
.toolbar .control {
    position: static;
}
.prev {
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
}
.next {
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
}
.counter {
    position: absolute;
    bottom: 18px;
    right: 24px;
    z-index: 40;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
    color: var(--deck-subtle);
}

/* Slide transitions: a restrained fade + drift. */
.slide-next-enter-active,
.slide-prev-enter-active {
    transition:
        opacity 0.28s ease,
        transform 0.28s ease;
}
.slide-next-leave-active,
.slide-prev-leave-active {
    transition:
        opacity 0.18s ease,
        transform 0.18s ease;
}
.slide-next-enter-from {
    opacity: 0;
    transform: translateX(28px);
}
.slide-next-leave-to {
    opacity: 0;
    transform: translateX(-20px);
}
.slide-prev-enter-from {
    opacity: 0;
    transform: translateX(-28px);
}
.slide-prev-leave-to {
    opacity: 0;
    transform: translateX(20px);
}
</style>
