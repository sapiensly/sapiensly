<script setup lang="ts">
import { type Artifact, extensionFor } from '@/lib/artifacts';
import DOMPurify from 'dompurify';
import { Check, Code2, Copy, Download, Eye, X } from 'lucide-vue-next';
import { marked } from 'marked';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{ artifact: Artifact }>();
const emit = defineEmits<{ close: [] }>();

const previewable = computed(() => ['html', 'svg', 'markdown'].includes(props.artifact.type));
const view = ref<'preview' | 'code'>('code');
const copied = ref(false);

// Default to preview for renderable types; reset when switching artifacts.
watch(
    () => props.artifact.id,
    () => {
        view.value = previewable.value ? 'preview' : 'code';
    },
    { immediate: true },
);

const renderedMarkdown = computed(() =>
    DOMPurify.sanitize(marked.parse(props.artifact.content, { async: false, breaks: true, gfm: true }) as string),
);
const safeSvg = computed(() => DOMPurify.sanitize(props.artifact.content, { USE_PROFILES: { svg: true, svgFilters: true } }));

async function copy() {
    try {
        await navigator.clipboard.writeText(props.artifact.content);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        /* clipboard unavailable */
    }
}

function download() {
    const blob = new Blob([props.artifact.content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const safeName = (props.artifact.title || 'artifact').replace(/[^a-z0-9-_ ]/gi, '').trim() || 'artifact';
    a.href = url;
    a.download = `${safeName}.${extensionFor(props.artifact)}`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <section class="flex h-full min-h-0 w-full flex-col border-l border-soft bg-surface">
        <!-- Header -->
        <header class="flex items-center gap-2 border-b border-soft px-4 py-3">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink">{{ artifact.title }}</p>
                <p class="truncate text-[11px] text-ink-subtle">
                    {{ artifact.language || t(`chat.artifact.type_${artifact.type}`) }}
                </p>
            </div>

            <div v-if="previewable" class="mr-1 flex rounded-lg border border-medium p-0.5">
                <button
                    type="button"
                    :class="['rounded-md px-2 py-1 text-xs transition-colors', view === 'preview' ? 'bg-accent-blue text-white' : 'text-ink-muted hover:text-ink']"
                    @click="view = 'preview'"
                >
                    <Eye class="size-3.5" />
                </button>
                <button
                    type="button"
                    :class="['rounded-md px-2 py-1 text-xs transition-colors', view === 'code' ? 'bg-accent-blue text-white' : 'text-ink-muted hover:text-ink']"
                    @click="view = 'code'"
                >
                    <Code2 class="size-3.5" />
                </button>
            </div>

            <button type="button" :title="t('chat.copy')" class="rounded-lg p-1.5 text-ink-muted transition-colors hover:bg-white/10 hover:text-ink" @click="copy">
                <Check v-if="copied" class="size-4 text-sp-success" />
                <Copy v-else class="size-4" />
            </button>
            <button type="button" :title="t('chat.artifact.download')" class="rounded-lg p-1.5 text-ink-muted transition-colors hover:bg-white/10 hover:text-ink" @click="download">
                <Download class="size-4" />
            </button>
            <button type="button" :title="t('common.close')" class="rounded-lg p-1.5 text-ink-muted transition-colors hover:bg-white/10 hover:text-ink" @click="emit('close')">
                <X class="size-4" />
            </button>
        </header>

        <!-- Body -->
        <div class="min-h-0 flex-1 overflow-auto">
            <iframe
                v-if="previewable && view === 'preview' && artifact.type === 'html'"
                :srcdoc="artifact.content"
                sandbox="allow-scripts allow-forms allow-popups"
                class="h-full w-full border-0 bg-white"
                title="artifact preview"
            />
            <div
                v-else-if="previewable && view === 'preview' && artifact.type === 'svg'"
                class="flex min-h-full items-center justify-center bg-white p-6"
                v-html="safeSvg"
            />
            <div
                v-else-if="previewable && view === 'preview' && artifact.type === 'markdown'"
                class="sp-chat-prose prose prose-sm max-w-none p-5 dark:prose-invert"
                v-html="renderedMarkdown"
            />
            <pre v-else class="m-0 h-full overflow-auto bg-navy p-4 text-[13px] leading-relaxed"><code class="font-mono text-ink">{{ artifact.content }}</code></pre>
        </div>
    </section>
</template>
