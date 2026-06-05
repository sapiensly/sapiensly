<script setup lang="ts">
import StarterKit from '@tiptap/starter-kit';
import { Editor, EditorContent } from '@tiptap/vue-3';
import { Bold, Heading2, Heading3, Italic, Link as LinkIcon, List, ListOrdered, Underline as UnderlineIcon, Unlink } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

const props = defineProps<{
    modelValue: string;
    inputId?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const t = themeTokens(useRuntimeTheme());

// `editing` guards a re-entrancy loop: TipTap's onUpdate emits, which we
// echo to v-model; the watcher below would otherwise round-trip that value
// back into the editor and reset the caret on every keystroke.
const editing = ref(false);

const editor = new Editor({
    content: props.modelValue || '',
    extensions: [
        StarterKit.configure({
            // Disable marks/nodes the toolbar doesn't expose — keeps the
            // saved HTML small and predictable for the server sanitiser.
            // Underline and Link ship inside StarterKit in Tiptap v3.
            heading: { levels: [2, 3] },
            blockquote: false,
            codeBlock: false,
            code: false,
            horizontalRule: false,
            strike: false,
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
    ],
    editorProps: {
        attributes: {
            class: 'tiptap-content min-h-[120px] p-3 prose prose-sm max-w-none focus:outline-none',
        },
    },
    onUpdate({ editor }) {
        editing.value = true;
        emit('update:modelValue', editor.getHTML());
        // Flip back next tick so an external value change (e.g. form reset)
        // is still applied.
        Promise.resolve().then(() => { editing.value = false; });
    },
});

watch(() => props.modelValue, (value) => {
    if (editing.value) return;
    const current = editor.getHTML();
    if (current === value) return;
    editor.commands.setContent(value || '', { emitUpdate: false });
});

onBeforeUnmount(() => {
    editor.destroy();
});

function toggleBold() { editor.chain().focus().toggleBold().run(); }
function toggleItalic() { editor.chain().focus().toggleItalic().run(); }
function toggleUnderline() { editor.chain().focus().toggleUnderline().run(); }
function toggleH2() { editor.chain().focus().toggleHeading({ level: 2 }).run(); }
function toggleH3() { editor.chain().focus().toggleHeading({ level: 3 }).run(); }
function toggleBullet() { editor.chain().focus().toggleBulletList().run(); }
function toggleOrdered() { editor.chain().focus().toggleOrderedList().run(); }

function setLink() {
    const previousUrl = editor.getAttributes('link').href ?? '';
    // Native prompt keeps the MVP frictionless; we can swap for a modal later.
    const url = window.prompt('URL (https://… or mailto:…)', previousUrl);
    if (url === null) return; // cancelled
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
    <div :class="['rounded-md border', t.surfaceMuted]" :id="inputId">
        <!-- Toolbar -->
        <div :class="['flex flex-wrap items-center gap-0.5 border-b border-soft px-2 py-1.5', t.text]">
            <button
                type="button"
                @click="toggleBold"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('bold') ? 'bg-white/10 text-accent-blue' : '']"
                title="Bold (⌘B)"
            >
                <Bold class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleItalic"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('italic') ? 'bg-white/10 text-accent-blue' : '']"
                title="Italic (⌘I)"
            >
                <Italic class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleUnderline"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('underline') ? 'bg-white/10 text-accent-blue' : '']"
                title="Underline (⌘U)"
            >
                <UnderlineIcon class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft"></span>
            <button
                type="button"
                @click="toggleH2"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('heading', { level: 2 }) ? 'bg-white/10 text-accent-blue' : '']"
                title="Heading 2"
            >
                <Heading2 class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleH3"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('heading', { level: 3 }) ? 'bg-white/10 text-accent-blue' : '']"
                title="Heading 3"
            >
                <Heading3 class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft"></span>
            <button
                type="button"
                @click="toggleBullet"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('bulletList') ? 'bg-white/10 text-accent-blue' : '']"
                title="Bullet list"
            >
                <List class="size-3.5" />
            </button>
            <button
                type="button"
                @click="toggleOrdered"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('orderedList') ? 'bg-white/10 text-accent-blue' : '']"
                title="Numbered list"
            >
                <ListOrdered class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft"></span>
            <button
                type="button"
                @click="setLink"
                :class="['rounded p-1.5 transition-colors hover:bg-white/10', isActive('link') ? 'bg-white/10 text-accent-blue' : '']"
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

        <EditorContent :editor="editor" :class="[t.text]" />
    </div>
</template>

<style scoped>
:deep(.tiptap-content) {
    line-height: 1.55;
}
:deep(.tiptap-content h2) {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0.6em 0 0.4em;
}
:deep(.tiptap-content h3) {
    font-size: 1rem;
    font-weight: 600;
    margin: 0.6em 0 0.4em;
}
:deep(.tiptap-content p) {
    margin: 0.4em 0;
}
:deep(.tiptap-content ul),
:deep(.tiptap-content ol) {
    padding-left: 1.5em;
    margin: 0.4em 0;
}
:deep(.tiptap-content ul) {
    list-style: disc;
}
:deep(.tiptap-content ol) {
    list-style: decimal;
}
:deep(.tiptap-content a) {
    color: rgb(96 165 250);
    text-decoration: underline;
}
:deep(.tiptap-content p.is-editor-empty:first-child::before) {
    content: 'Write…';
    color: rgb(120 130 150);
    float: left;
    height: 0;
    pointer-events: none;
}
</style>
