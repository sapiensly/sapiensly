<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { html } from '@codemirror/lang-html';
import { EditorState } from '@codemirror/state';
import { oneDark } from '@codemirror/theme-one-dark';
import { EditorView, keymap, lineNumbers } from '@codemirror/view';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ modelValue: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const host = ref<HTMLElement | null>(null);
const { appearance } = useAppearance();
let view: EditorView | null = null;
let applying = false;

function isDark(): boolean {
    if (appearance.value === 'dark') return true;
    if (appearance.value === 'light') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function buildState(source: string, dark: boolean): EditorState {
    const extensions = [
        lineNumbers(),
        history(),
        keymap.of([...defaultKeymap, ...historyKeymap]),
        EditorView.lineWrapping,
        html({ matchClosingTags: true, autoCloseTags: true }),
        EditorView.updateListener.of((u) => {
            if (u.docChanged && !applying) {
                emit('update:modelValue', u.state.doc.toString());
            }
        }),
    ];
    if (dark) extensions.push(oneDark);
    return EditorState.create({ doc: source, extensions });
}

onMounted(() => {
    if (!host.value) return;
    view = new EditorView({
        parent: host.value,
        state: buildState(props.modelValue, isDark()),
    });
});

watch(
    () => props.modelValue,
    (next) => {
        if (!view) return;
        if (view.state.doc.toString() === next) return;
        applying = true;
        view.dispatch({
            changes: {
                from: 0,
                to: view.state.doc.length,
                insert: next,
            },
        });
        applying = false;
    },
);

watch(appearance, () => {
    if (!view) return;
    const current = view.state.doc.toString();
    view.setState(buildState(current, isDark()));
});

onBeforeUnmount(() => {
    view?.destroy();
    view = null;
});
</script>

<template>
    <div
        ref="host"
        class="cm-editor-host overflow-auto rounded border bg-card text-sm"
    />
</template>

<style scoped>
.cm-editor-host {
    height: 100%;
}
.cm-editor-host :deep(.cm-editor) {
    height: 100%;
    min-height: 320px;
}
.cm-editor-host :deep(.cm-scroller) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.cm-editor-host :deep(.cm-editor.cm-focused) {
    outline: none;
}
</style>
