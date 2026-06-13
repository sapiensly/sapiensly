<script setup lang="ts">
import {
    Bold,
    Code,
    Heading1,
    Heading2,
    Heading3,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Quote,
    SquareCode,
    Strikethrough,
    Unlink,
} from '@lucide/vue';
import StarterKit from '@tiptap/starter-kit';
import { Editor, EditorContent } from '@tiptap/vue-3';
import { Markdown } from 'tiptap-markdown';
import { onBeforeUnmount, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

// `editing` guards a re-entrancy loop: TipTap's onUpdate emits, which we echo
// to v-model; the watcher below would otherwise round-trip that value back
// into the editor and reset the caret on every keystroke. Mirrors the pattern
// in runtime/blocks/RichTextEditor.vue.
let editing = false;

const editor = new Editor({
    // The Markdown extension parses a markdown string passed as `content` into
    // the ProseMirror document, so the editor opens already showing formatting.
    content: props.modelValue || '',
    extensions: [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },
            link: {
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                HTMLAttributes: {
                    rel: 'noopener noreferrer',
                    target: '_blank',
                },
            },
        }),
        Markdown.configure({
            html: false,
            breaks: true,
            linkify: true,
            transformPastedText: true,
            transformCopiedText: true,
        }),
    ],
    editorProps: {
        attributes: {
            class: 'tiptap-content prose prose-sm dark:prose-invert max-w-none min-h-[24rem] p-4 focus:outline-none',
        },
    },
    onUpdate({ editor }) {
        editing = true;
        emit('update:modelValue', editor.storage.markdown.getMarkdown());
        Promise.resolve().then(() => {
            editing = false;
        });
    },
});

watch(
    () => props.modelValue,
    (value) => {
        if (editing) return;
        if (editor.storage.markdown.getMarkdown() === value) return;
        editor.commands.setContent(value || '', { emitUpdate: false });
    },
);

onBeforeUnmount(() => {
    editor.destroy();
});

function toggleBold() {
    editor.chain().focus().toggleBold().run();
}
function toggleItalic() {
    editor.chain().focus().toggleItalic().run();
}
function toggleStrike() {
    editor.chain().focus().toggleStrike().run();
}
function toggleCode() {
    editor.chain().focus().toggleCode().run();
}
function toggleHeading(level: 1 | 2 | 3) {
    editor.chain().focus().toggleHeading({ level }).run();
}
function toggleBullet() {
    editor.chain().focus().toggleBulletList().run();
}
function toggleOrdered() {
    editor.chain().focus().toggleOrderedList().run();
}
function toggleBlockquote() {
    editor.chain().focus().toggleBlockquote().run();
}
function toggleCodeBlock() {
    editor.chain().focus().toggleCodeBlock().run();
}

function setLink() {
    const previousUrl = editor.getAttributes('link').href ?? '';
    const url = window.prompt('URL (https://… or mailto:…)', previousUrl);
    if (url === null) return;
    if (url === '') {
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}
function unsetLink() {
    editor.chain().focus().extendMarkRange('link').unsetLink().run();
}

function isActive(name: string, attrs?: Record<string, unknown>): boolean {
    return attrs ? editor.isActive(name, attrs) : editor.isActive(name);
}
</script>

<template>
    <div class="overflow-hidden rounded-xs border border-soft bg-white/[0.03]">
        <!-- Toolbar -->
        <div
            class="flex flex-wrap items-center gap-0.5 border-b border-soft px-2 py-1.5 text-ink"
        >
            <button
                type="button"
                @click="toggleBold"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('bold') ? 'bg-white/10 text-accent-blue' : '',
                ]"
                title="Bold (⌘B)"
            >
                <Bold class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleItalic"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('italic') ? 'bg-white/10 text-accent-blue' : '',
                ]"
                title="Italic (⌘I)"
            >
                <Italic class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleStrike"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('strike') ? 'bg-white/10 text-accent-blue' : '',
                ]"
                title="Strikethrough"
            >
                <Strikethrough class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleCode"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('code') ? 'bg-white/10 text-accent-blue' : '',
                ]"
                title="Inline code"
            >
                <Code class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft"></span>
            <button
                type="button"
                @click="toggleHeading(1)"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('heading', { level: 1 })
                        ? 'bg-white/10 text-accent-blue'
                        : '',
                ]"
                title="Heading 1"
            >
                <Heading1 class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleHeading(2)"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('heading', { level: 2 })
                        ? 'bg-white/10 text-accent-blue'
                        : '',
                ]"
                title="Heading 2"
            >
                <Heading2 class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleHeading(3)"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('heading', { level: 3 })
                        ? 'bg-white/10 text-accent-blue'
                        : '',
                ]"
                title="Heading 3"
            >
                <Heading3 class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft"></span>
            <button
                type="button"
                @click="toggleBullet"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('bulletList')
                        ? 'bg-white/10 text-accent-blue'
                        : '',
                ]"
                title="Bullet list"
            >
                <List class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleOrdered"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('orderedList')
                        ? 'bg-white/10 text-accent-blue'
                        : '',
                ]"
                title="Numbered list"
            >
                <ListOrdered class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleBlockquote"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('blockquote')
                        ? 'bg-white/10 text-accent-blue'
                        : '',
                ]"
                title="Blockquote"
            >
                <Quote class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleCodeBlock"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('codeBlock') ? 'bg-white/10 text-accent-blue' : '',
                ]"
                title="Code block"
            >
                <SquareCode class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft"></span>
            <button
                type="button"
                @click="setLink"
                :class="[
                    'rounded p-1.5 transition-colors hover:bg-white/10',
                    isActive('link') ? 'bg-white/10 text-accent-blue' : '',
                ]"
                title="Add / edit link"
            >
                <LinkIcon class="size-3.5" />
            </button>
            <button
                v-if="isActive('link')"
                type="button"
                @click="unsetLink"
                class="rounded p-1.5 transition-colors hover:bg-white/10"
                title="Remove link"
            >
                <Unlink class="size-3.5" />
            </button>
        </div>

        <EditorContent :editor="editor" class="text-ink" />
    </div>
</template>

<style scoped>
:deep(.tiptap-content) {
    line-height: 1.6;
}
:deep(.tiptap-content:focus) {
    outline: none;
}
:deep(.tiptap-content p.is-editor-empty:first-child::before) {
    content: 'Write Markdown…';
    color: rgb(120 130 150);
    float: left;
    height: 0;
    pointer-events: none;
}
</style>
