<script setup lang="ts">
import { adminHighlight, adminTheme } from '@/lib/documents/code-editor-theme';
import { html } from '@codemirror/lang-html';
import { syntaxHighlighting } from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import { EditorView, lineNumbers } from '@codemirror/view';
import { Check, Copy } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{ source: string; language?: 'html' }>();

const host = ref<HTMLElement | null>(null);
const copied = ref(false);
let view: EditorView | null = null;

const { t } = useI18n();

// Read-only counterpart of HtmlCodeEditor — shares the same admin-palette
// theme + highlight style so the Show page's "Code" tab reads exactly like
// the workbench editor the user edits in.
function buildState(source: string): EditorState {
    return EditorState.create({
        doc: source,
        extensions: [
            lineNumbers(),
            EditorState.readOnly.of(true),
            EditorView.editable.of(false),
            EditorView.lineWrapping,
            html({ matchClosingTags: true, autoCloseTags: false }),
            syntaxHighlighting(adminHighlight),
            adminTheme,
        ],
    });
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
        state: buildState(props.source),
    });
});

watch(
    () => props.source,
    (next) => {
        if (!view) return;
        view.setState(buildState(next));
    },
);

onBeforeUnmount(() => {
    view?.destroy();
    view = null;
});
</script>

<template>
    <div class="relative rounded-xs border border-medium bg-white/5">
        <button
            type="button"
            class="absolute top-2 right-2 z-10 inline-flex items-center gap-1 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
            @click="copySource"
        >
            <Check v-if="copied" class="size-3.5" />
            <Copy v-else class="size-3.5" />
            {{ copied ? t('common.saved') : t('documents.show.copy_link') }}
        </button>
        <div ref="host" class="sp-code-editor max-h-[600px] overflow-auto" />
    </div>
</template>
