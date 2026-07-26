<script setup lang="ts">
import DOMPurify from 'dompurify';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useLandingMotion } from '../useLandingMotion';

interface HtmlBlock {
    id: string;
    type: 'html';
    content: string;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: HtmlBlock }>();

// The content is already sanitised server-side (LandingHtmlSanitizer) at save
// time — that is the real trust boundary. This client DOMPurify pass is
// defense-in-depth. Styling comes entirely from settings.custom_css targeting
// the authored classes; this component adds none of its own. data-sp-* hooks
// are preserved (DOMPurify keeps data-* + aria-*) so the motion runtime can
// hydrate them. In SSR there is no DOM for DOMPurify — render the (already
// server-sanitised) content as-is so public landings server-render for SEO.
const html = computed(() =>
    typeof window === 'undefined'
        ? (props.block.content ?? '')
        : DOMPurify.sanitize(props.block.content ?? '', {
              ADD_ATTR: ['target'],
          }),
);

// Hydrate the safe data-sp-* motion vocabulary over the rendered markup — after
// the initial render and whenever the content changes (builder preview edits).
const rootEl = ref<HTMLElement | null>(null);
const { hydrate } = useLandingMotion(rootEl);

// Stamp the block id onto the authored top-level element(s). The wrapper is
// display:contents (so the authored <section> is the layout box), and
// inheritAttrs is off, so AppRenderer's data-block-id never reaches the DOM —
// without this the fine-tune manual mode can't select a landing section (and
// selectionRect would measure a boxless wrapper). Harmless on the public page:
// it's just a data attribute on the section.
function stampBlockId(): void {
    const el = rootEl.value;
    if (!el) return;
    for (const child of Array.from(el.children)) {
        child.setAttribute('data-block-id', props.block.id);
        child.setAttribute('data-block-type', 'html');
    }
}

function afterRender(): void {
    hydrate();
    stampBlockId();
}

onMounted(() => nextTick(afterRender));
watch(html, () => nextTick(afterRender));
</script>

<template>
    <!-- display:contents so the authored <section> is the layout box (full-bleed
         landing sections stay edge to edge, not boxed by this wrapper). -->
    <div ref="rootEl" class="sp-html-block" v-html="html" />
</template>

<style scoped>
.sp-html-block {
    display: contents;
}
</style>
