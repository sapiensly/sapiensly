import { HighlightStyle } from '@codemirror/language';
import { EditorView } from '@codemirror/view';
import { tags as t } from '@lezer/highlight';

/**
 * Shared CodeMirror theme + syntax highlighting for artifact HTML code.
 * Used by both the workbench editor (HtmlCodeEditor) and the Show page
 * viewer (CodeMirrorViewer) so a user's code reads the same no matter
 * which surface they land on.
 *
 * Colors are mapped to admin-v2 tokens — cyan for tags, magenta for
 * attrs, success-green for strings — so editor output feels like part
 * of the same design system rather than a generic dark theme.
 */
export const adminHighlight = HighlightStyle.define([
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

export const adminTheme = EditorView.theme(
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
