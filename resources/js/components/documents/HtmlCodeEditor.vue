<script setup lang="ts">
import { adminHighlight, adminTheme } from '@/lib/documents/code-editor-theme';
import { defaultKeymap } from '@codemirror/commands';
import { html } from '@codemirror/lang-html';
import { syntaxHighlighting } from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers } from '@codemirror/view';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
}>();
const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const host = ref<HTMLElement | null>(null);
let view: EditorView | null = null;

onMounted(() => {
    if (!host.value) return;

    const state = EditorState.create({
        doc: props.modelValue,
        extensions: [
            lineNumbers(),
            keymap.of(defaultKeymap),
            html(),
            syntaxHighlighting(adminHighlight),
            adminTheme,
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    emit('update:modelValue', update.state.doc.toString());
                }
            }),
        ],
    });

    view = new EditorView({ state, parent: host.value });
});

onBeforeUnmount(() => {
    view?.destroy();
    view = null;
});

// External updates (AI refinement, Visual-mode commit) must flow back into
// the editor without clobbering the user's cursor when they're mid-typing.
// We only push if the external value actually differs from what the editor
// currently holds.
watch(
    () => props.modelValue,
    (next) => {
        if (!view) return;
        const current = view.state.doc.toString();
        if (current === next) return;
        view.dispatch({
            changes: { from: 0, to: current.length, insert: next },
        });
    },
);
</script>

<template>
    <div
        ref="host"
        class="sp-code-editor h-full w-full rounded-xs border border-medium bg-white/5"
    />
</template>
