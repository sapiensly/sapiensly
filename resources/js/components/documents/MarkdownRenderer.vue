<script setup lang="ts">
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps<{ source: string }>();

const container = ref<HTMLElement | null>(null);

const rendered = computed(() => {
    const html = marked.parse(props.source ?? '', {
        gfm: true,
        breaks: true,
    }) as string;

    return DOMPurify.sanitize(html, {
        ADD_ATTR: ['target'],
    });
});

async function renderDiagrams() {
    if (!container.value) return;

    const blocks = Array.from(
        container.value.querySelectorAll<HTMLElement>(
            'pre > code.language-mermaid',
        ),
    );
    if (blocks.length === 0) return;

    const { default: mermaid } = await import('mermaid');
    mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });

    for (const [index, block] of blocks.entries()) {
        const code = block.textContent ?? '';
        const host = document.createElement('div');
        host.className = 'mermaid-rendered my-4 flex justify-center';
        try {
            const { svg } = await mermaid.render(
                `mermaid-${Date.now()}-${index}`,
                code,
            );
            host.innerHTML = svg;
        } catch (e) {
            host.innerHTML = `<pre class="text-sm text-destructive">Mermaid render error: ${
                e instanceof Error ? e.message : String(e)
            }</pre>`;
        }
        block.parentElement?.replaceWith(host);
    }
}

onMounted(async () => {
    await nextTick();
    await renderDiagrams();
});

watch(
    () => props.source,
    async () => {
        await nextTick();
        await renderDiagrams();
    },
);
</script>

<template>
    <div
        ref="container"
        class="prose prose-sm max-w-none rounded border bg-card p-4 dark:prose-invert"
        v-html="rendered"
    />
</template>
