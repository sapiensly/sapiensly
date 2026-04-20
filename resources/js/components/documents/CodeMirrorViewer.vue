<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import { html } from '@codemirror/lang-html';
import { EditorState } from '@codemirror/state';
import { oneDark } from '@codemirror/theme-one-dark';
import { EditorView, lineNumbers } from '@codemirror/view';
import { Check, Copy } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ source: string; language?: 'html' }>();

const host = ref<HTMLElement | null>(null);
const copied = ref(false);
let view: EditorView | null = null;

const { appearance } = useAppearance();

function buildState(source: string, dark: boolean): EditorState {
    const extensions = [
        lineNumbers(),
        EditorState.readOnly.of(true),
        EditorView.editable.of(false),
        EditorView.lineWrapping,
        html({ matchClosingTags: true, autoCloseTags: false }),
    ];
    if (dark) {
        extensions.push(oneDark);
    }
    return EditorState.create({ doc: source, extensions });
}

function isDark(): boolean {
    if (appearance.value === 'dark') return true;
    if (appearance.value === 'light') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

async function copySource() {
    await navigator.clipboard.writeText(props.source);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

onMounted(() => {
    if (!host.value) return;
    view = new EditorView({
        parent: host.value,
        state: buildState(props.source, isDark()),
    });
});

watch(
    () => props.source,
    (next) => {
        if (!view) return;
        view.setState(buildState(next, isDark()));
    },
);

watch(appearance, () => {
    if (!view) return;
    view.setState(buildState(props.source, isDark()));
});

onBeforeUnmount(() => {
    view?.destroy();
    view = null;
});
</script>

<template>
    <div class="relative rounded border bg-card">
        <Button
            variant="outline"
            size="sm"
            class="absolute top-2 right-2 z-10 h-7 gap-1"
            @click="copySource"
        >
            <Check v-if="copied" class="h-3.5 w-3.5" />
            <Copy v-else class="h-3.5 w-3.5" />
            <span class="text-xs">{{ copied ? 'Copied' : 'Copy' }}</span>
        </Button>
        <div
            ref="host"
            class="cm-viewer max-h-[600px] overflow-auto text-sm"
        />
    </div>
</template>

<style scoped>
.cm-viewer :deep(.cm-editor) {
    height: auto;
}
.cm-viewer :deep(.cm-scroller) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
</style>
