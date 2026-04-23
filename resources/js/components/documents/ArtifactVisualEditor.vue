<script setup lang="ts">
import { extractBody, replaceBodyContents } from '@/lib/documents/html';
import {
    Bold,
    Heading1,
    Heading2,
    Heading3,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Underline,
} from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * True-WYSIWYG artifact editor. Renders the full artifact HTML inside an
 * iframe (with the artifact's own CSS applied) and makes the body
 * contenteditable so the user edits directly on the rendered page.
 *
 * Scripts are stripped before loading so the artifact's own JS doesn't
 * fight with edits (live clocks, interactive demos, etc. pause in edit
 * mode). They're preserved in the parent `modelValue` and re-applied in
 * other modes (AI preview, Code) since those modes receive the full,
 * untouched HTML.
 */

const props = defineProps<{
    modelValue: string;
}>();
const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const { t } = useI18n();

const iframe = ref<HTMLIFrameElement | null>(null);

// Suppresses the external-watcher's reload when the update was fired by
// our own input handler — otherwise every keystroke would blow away the
// iframe and the caret with it.
let lastEmittedBody = '';
let inputHandler: ((e: Event) => void) | null = null;

function stripScripts(html: string): string {
    return html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
}

function loadIntoIframe() {
    const el = iframe.value;
    if (!el) return;

    const doc = el.contentDocument;
    if (!doc) return;

    // Write the full HTML so head styles apply. We strip script blocks
    // since they'd execute inside the edit iframe and interfere with
    // cursor / mutation tracking.
    const html = stripScripts(props.modelValue);
    doc.open();
    doc.write(html);
    doc.close();

    if (!doc.body) return;

    doc.body.contentEditable = 'true';
    doc.body.style.outline = 'none';
    doc.body.style.minHeight = '100%';

    detachListeners(doc);
    const handler = () => {
        if (!doc.body) return;
        const bodyHtml = doc.body.innerHTML;
        lastEmittedBody = bodyHtml;
        emit('update:modelValue', replaceBodyContents(props.modelValue, bodyHtml));
    };
    doc.body.addEventListener('input', handler);
    inputHandler = handler;
    lastEmittedBody = doc.body.innerHTML;
}

function detachListeners(doc: Document) {
    if (!inputHandler || !doc.body) return;
    doc.body.removeEventListener('input', inputHandler);
    inputHandler = null;
}

// execCommand is deprecated but the only cross-browser way to apply
// simple formatting inside a contenteditable region. Fine for v1; we can
// switch to Selection / Range APIs later if we outgrow it.
function exec(command: string, value?: string) {
    const doc = iframe.value?.contentDocument;
    if (!doc) return;
    doc.execCommand(command, false, value);
    // execCommand doesn't always fire an input event — synthesize the
    // emit so the parent stays in sync with the formatted HTML.
    if (doc.body && inputHandler) {
        inputHandler(new Event('input'));
    }
}

function toggleBold() {
    exec('bold');
}
function toggleItalic() {
    exec('italic');
}
function toggleUnderline() {
    exec('underline');
}
function heading(level: 1 | 2 | 3) {
    exec('formatBlock', `H${level}`);
}
function bulletList() {
    exec('insertUnorderedList');
}
function orderedList() {
    exec('insertOrderedList');
}
function setLink() {
    const doc = iframe.value?.contentDocument;
    if (!doc) return;
    const url = window.prompt(t('documents.workbench.visual.link_prompt'), '');
    if (url === null) return;
    if (url === '') {
        exec('unlink');
        return;
    }
    exec('createLink', url);
}

onMounted(() => {
    loadIntoIframe();
});

onBeforeUnmount(() => {
    const doc = iframe.value?.contentDocument;
    if (doc) detachListeners(doc);
});

// External updates (AI refinement, Code-mode edits) only trigger a full
// iframe reload when they actually differ from what we last emitted.
watch(
    () => props.modelValue,
    (next) => {
        const doc = iframe.value?.contentDocument;
        if (!doc?.body) return;
        const incomingBody = extractBody(next);
        if (incomingBody === lastEmittedBody) return;
        loadIntoIframe();
    },
);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div
            class="mb-3 flex flex-wrap items-center gap-1 self-start rounded-pill border border-soft bg-white/5 p-1 text-[11px] font-medium text-ink-muted"
        >
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.bold')"
                @click="toggleBold"
            >
                <Bold class="size-3.5" />
            </button>
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.italic')"
                @click="toggleItalic"
            >
                <Italic class="size-3.5" />
            </button>
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.underline')"
                @click="toggleUnderline"
            >
                <Underline class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft" />
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.h1')"
                @click="heading(1)"
            >
                <Heading1 class="size-3.5" />
            </button>
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.h2')"
                @click="heading(2)"
            >
                <Heading2 class="size-3.5" />
            </button>
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.h3')"
                @click="heading(3)"
            >
                <Heading3 class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft" />
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.bullet_list')"
                @click="bulletList"
            >
                <List class="size-3.5" />
            </button>
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.ordered_list')"
                @click="orderedList"
            >
                <ListOrdered class="size-3.5" />
            </button>
            <span class="mx-1 h-4 w-px bg-soft" />
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-pill transition-colors hover:bg-white/10 hover:text-ink"
                :aria-label="t('documents.workbench.visual.link')"
                @click="setLink"
            >
                <LinkIcon class="size-3.5" />
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-hidden rounded-xs border border-medium bg-white">
            <iframe
                ref="iframe"
                class="h-full w-full border-0"
                sandbox="allow-same-origin"
            />
        </div>

        <p class="mt-2 text-[11px] text-ink-subtle">
            {{ t('documents.workbench.visual.scope_hint') }}
        </p>
    </div>
</template>
