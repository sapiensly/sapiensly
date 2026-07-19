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
// hydrate them.
const html = computed(() =>
    DOMPurify.sanitize(props.block.content ?? '', {
        ADD_ATTR: ['target'],
    }),
);

// Hydrate the safe data-sp-* motion vocabulary over the rendered markup — after
// the initial render and whenever the content changes (builder preview edits).
const rootEl = ref<HTMLElement | null>(null);
const { hydrate } = useLandingMotion(rootEl);
onMounted(() => nextTick(hydrate));
watch(html, () => nextTick(hydrate));
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
