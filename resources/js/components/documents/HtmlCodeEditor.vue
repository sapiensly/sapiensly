<script setup lang="ts">
import { html } from '@codemirror/lang-html';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers } from '@codemirror/view';
import { defaultKeymap } from '@codemirror/commands';
import { tags as t } from '@lezer/highlight';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
}>();
const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const host = ref<HTMLElement | null>(null);
let view: EditorView | null = null;

// Syntax highlighting palette tuned to the admin tokens. HTML tags use
// the cyan accent, attributes use spectrum-magenta, strings use success
// green — consistent with the icon tints we use elsewhere so editor
// colors feel like part of the same design system.
const adminHighlight = HighlightStyle.define([
    { tag: t.tagName, color: '#00e5ff' },
    { tag: t.angleBracket, color: '#5a6378' },
    { tag: [t.attributeName, t.propertyName], color: '#d946ef' },
    { tag: [t.attributeValue, t.string, t.special(t.string)], color: '#22c55e' },
    { tag: [t.number, t.bool, t.null], color: '#f59e0b' },
    { tag: [t.keyword, t.controlKeyword, t.modifier, t.self], color: '#0096ff', fontWeight: '500' },
    { tag: t.operator, color: '#8890a6' },
    { tag: t.meta, color: '#0096ff' },
    { tag: t.comment, color: 'rgba(255,255,255,0.4)', fontStyle: 'italic' },
    { tag: t.bracket, color: '#8890a6' },
    { tag: t.punctuation, color: '#8890a6' },
    { tag: [t.variableName, t.definition(t.variableName)], color: '#ffffff' },
    { tag: [t.function(t.variableName), t.function(t.propertyName)], color: '#00e5ff' },
    { tag: t.className, color: '#d946ef' },
    { tag: [t.typeName, t.namespace], color: '#00e5ff' },
    { tag: t.heading, color: '#0096ff', fontWeight: '600' },
    { tag: t.link, color: '#0096ff', textDecoration: 'underline' },
    { tag: t.invalid, color: '#ef4444' },
]);

// Admin-palette theme for CodeMirror. Scoped via `.sp-code-editor` on the
// host div so it doesn't leak into other CodeMirror instances on the page.
const adminTheme = EditorView.theme(
    {
        '&': {
            backgroundColor: 'transparent',
            color: 'var(--sp-text-primary)',
            fontSize: '13px',
            fontFamily: 'JetBrains Mono, ui-monospace, monospace',
            height: '100%',
        },
        '.cm-content': {
            caretColor: 'var(--sp-accent-blue)',
            padding: '16px 0',
        },
        '.cm-scroller': {
            overflow: 'auto',
        },
        '.cm-gutters': {
            backgroundColor: 'transparent',
            color: 'var(--sp-text-tertiary)',
            border: 'none',
        },
        '.cm-activeLine': {
            backgroundColor: 'rgba(255, 255, 255, 0.03)',
        },
        '.cm-activeLineGutter': {
            backgroundColor: 'rgba(255, 255, 255, 0.03)',
        },
        '.cm-selectionBackground, ::selection': {
            backgroundColor: 'color-mix(in oklab, var(--sp-accent-blue) 28%, transparent) !important',
        },
        '&.cm-focused': {
            outline: 'none',
        },
    },
    { dark: true },
);

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
