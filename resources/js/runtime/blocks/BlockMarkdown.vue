<script setup lang="ts">
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed } from 'vue';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface MarkdownBlock {
    id: string;
    type: 'markdown';
    content: string;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{ block: MarkdownBlock }>();
const t = themeTokens(useRuntimeTheme());

const html = computed(() => {
    const raw = marked.parse(props.block.content ?? '', { async: false }) as string;
    return DOMPurify.sanitize(raw);
});
</script>

<template>
    <div
        :class="['prose max-w-none leading-relaxed', t.text]"
        v-html="html"
    />
</template>

<style scoped>
/* Minimal markdown styling without pulling in @tailwindcss/typography. */
:deep(h1) { font-size: 1.5rem; font-weight: 600; margin-top: 0.5rem; margin-bottom: 0.5rem; }
:deep(h2) { font-size: 1.25rem; font-weight: 600; margin-top: 1rem; margin-bottom: 0.4rem; }
:deep(h3) { font-size: 1.1rem; font-weight: 600; margin-top: 0.75rem; margin-bottom: 0.3rem; }
:deep(p) { margin: 0.5rem 0; line-height: 1.55; }
:deep(ul), :deep(ol) { padding-left: 1.25rem; margin: 0.5rem 0; }
:deep(li) { margin: 0.15rem 0; }
:deep(code) { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.85em; padding: 0 0.25rem; background: rgba(255, 255, 255, 0.06); border-radius: 3px; }
:deep(pre) { background: rgba(0, 0, 0, 0.3); padding: 0.75rem; border-radius: 4px; overflow-x: auto; font-size: 0.85em; }
:deep(pre code) { background: transparent; padding: 0; }
:deep(blockquote) { border-left: 3px solid currentColor; padding-left: 0.75rem; opacity: 0.8; margin: 0.5rem 0; }
:deep(a) { color: var(--sp-accent-blue, #3B82F6); text-decoration: underline; }
:deep(strong) { font-weight: 600; }
:deep(table) { border-collapse: collapse; margin: 0.5rem 0; }
:deep(th), :deep(td) { border: 1px solid currentColor; padding: 0.25rem 0.5rem; opacity: 0.9; }
</style>
