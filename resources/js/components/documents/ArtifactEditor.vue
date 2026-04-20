<script setup lang="ts">
import CodeMirrorEditor from '@/components/documents/CodeMirrorEditor.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { router } from '@inertiajs/vue3';
import {
    Bold,
    Code2,
    Eye,
    Heading1,
    Heading2,
    Image,
    Italic,
    Link as LinkIcon,
    Palette,
    Type,
    Underline as UnderlineIcon,
} from 'lucide-vue-next';
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    documentId: string;
    initialBody: string;
}

const props = defineProps<Props>();
const open = defineModel<boolean>('open', { required: true });
const { t } = useI18n();

const activeTab = ref<'visual' | 'code'>('visual');
const source = ref(props.initialBody);
const saving = ref(false);
const iframeRef = ref<HTMLIFrameElement | null>(null);
// Last source value that was actually rendered into the iframe. Lets us skip
// reloads when the user simply switches tabs without editing anything.
let lastLoadedSource: string | null = null;

// Regex that matches script elements (opening through closing tag). Built
// from fragments so the Vue SFC tokenizer does not mistake it for the end
// of this setup block.
const SCRIPT_OPEN = '<' + 'script';
const SCRIPT_CLOSE = '<\\/' + 'scr' + 'ipt>';
const SCRIPT_RE = new RegExp(`${SCRIPT_OPEN}[\\s\\S]*?${SCRIPT_CLOSE}`, 'gi');

// Strip embedded script elements for the visual editing pass; the runtime
// behaviour lives on the Code tab. This keeps a stray payload from hijacking
// the editor's execution context.
function stripScripts(html: string): string {
    return html.replace(SCRIPT_RE, '');
}

function buildEditableHtml(srcHtml: string): string {
    const stripped = stripScripts(srcHtml);
    // Inject a marker <meta> + contenteditable on body. We keep the original
    // DOCTYPE/<html>/<head> when present so user styles still apply.
    if (/<body[\s>]/i.test(stripped)) {
        return stripped.replace(
            /<body([^>]*)>/i,
            '<body$1 contenteditable="true" spellcheck="false">',
        );
    }
    // Fragment-only: wrap in a minimal document so contenteditable works.
    return `<!doctype html><html><head><meta charset="utf-8"></head><body contenteditable="true" spellcheck="false">${stripped}</body></html>`;
}

async function mountIframe(force = false) {
    // Two ticks: one for the Tabs switch, one to be safe if the dialog just
    // opened and the portal content is still attaching.
    await nextTick();
    await nextTick();

    const iframe = iframeRef.value;
    if (!iframe) return;

    if (!force && lastLoadedSource === source.value) {
        return;
    }

    const editable = buildEditableHtml(source.value);
    lastLoadedSource = source.value;
    iframe.srcdoc = editable;
}

function readBodyFromIframe(): string | null {
    const iframe = iframeRef.value;
    if (!iframe || !iframe.contentDocument) return null;
    const bodyEl = iframe.contentDocument.body;
    if (!bodyEl) return null;
    // Remove contenteditable/spellcheck attributes we injected.
    const clone = bodyEl.cloneNode(true) as HTMLElement;
    clone.removeAttribute('contenteditable');
    clone.removeAttribute('spellcheck');
    // Re-splice the original <script> blocks at the end of body.
    const scripts = Array.from(source.value.matchAll(SCRIPT_RE));
    const tail = scripts.map((m) => m[0]).join('\n');

    // Preserve the original doctype + html shell when present, only swap body.
    const original = source.value;
    if (/<body[\s>]/i.test(original)) {
        return original.replace(
            /<body([^>]*)>[\s\S]*?<\/body>/i,
            `<body$1>${clone.innerHTML}${tail ? '\n' + tail : ''}</body>`,
        );
    }
    // Fragment: return body inner content + tail scripts.
    return clone.innerHTML + (tail ? '\n' + tail : '');
}

function syncFromVisualToCode() {
    const next = readBodyFromIframe();
    if (next === null) return;
    if (next !== source.value) {
        source.value = next;
    }
    // After sync, the iframe content and source are in agreement — record
    // it so a no-op round-trip back to Visual skips a reload.
    lastLoadedSource = next;
}

async function onTabChange(value: string) {
    if (value === 'code') {
        syncFromVisualToCode();
    }
    activeTab.value = value as 'visual' | 'code';
    if (value === 'visual') {
        await mountIframe();
    }
}

function exec(command: string, value?: string) {
    const iframe = iframeRef.value;
    if (!iframe || !iframe.contentDocument) return;
    iframe.contentWindow?.focus();
    // execCommand is deprecated but still universally supported for basic
    // inline formatting. Sufficient for the text/colour/link/image scope.
    iframe.contentDocument.execCommand(command, false, value);
}

function promptLink() {
    const url = window.prompt(t('documents.artifact_editor.link_prompt'), 'https://');
    if (url) exec('createLink', url);
}

function promptImage() {
    const url = window.prompt(t('documents.artifact_editor.image_prompt'), 'https://');
    if (url) exec('insertImage', url);
}

const textColor = ref('#111827');
const bgColor = ref('#fef3c7');

function applyTextColor() {
    exec('foreColor', textColor.value);
}
function applyBgColor() {
    exec('hiliteColor', bgColor.value);
}

async function save() {
    if (activeTab.value === 'visual') {
        syncFromVisualToCode();
    }
    saving.value = true;
    router.put(
        `/documents/${props.documentId}`,
        { body: source.value },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}

function cancel() {
    open.value = false;
}

watch(open, async (isOpen) => {
    if (isOpen) {
        source.value = props.initialBody;
        activeTab.value = 'visual';
        lastLoadedSource = null;
        await mountIframe(true);
    }
});

watch(
    () => props.initialBody,
    (next) => {
        if (!open.value) source.value = next;
    },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex h-[95vh] w-[98vw] max-w-[98vw] flex-col gap-3 p-4 sm:max-w-[98vw]"
        >
            <DialogHeader class="shrink-0">
                <DialogTitle>{{ t('documents.artifact_editor.title') }}</DialogTitle>
                <DialogDescription>
                    {{ t('documents.artifact_editor.description') }}
                </DialogDescription>
            </DialogHeader>

            <Tabs
                :model-value="activeTab"
                :unmount-on-hide="false"
                class="flex min-h-0 w-full flex-1 flex-col"
                @update:model-value="onTabChange"
            >
                <TabsList>
                    <TabsTrigger value="visual">
                        <Eye class="mr-1.5 h-3.5 w-3.5" />
                        {{ t('documents.artifact_editor.visual_tab') }}
                    </TabsTrigger>
                    <TabsTrigger value="code">
                        <Code2 class="mr-1.5 h-3.5 w-3.5" />
                        {{ t('documents.artifact_editor.code_tab') }}
                    </TabsTrigger>
                </TabsList>

                <TabsContent
                    value="visual"
                    class="mt-3 flex min-h-0 flex-1 flex-col gap-2"
                >
                    <!-- Toolbar -->
                    <div
                        class="flex flex-wrap items-center gap-1 rounded border bg-muted/50 p-1"
                    >
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.bold')"
                            @click="exec('bold')"
                        >
                            <Bold class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.italic')"
                            @click="exec('italic')"
                        >
                            <Italic class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.underline')"
                            @click="exec('underline')"
                        >
                            <UnderlineIcon class="h-4 w-4" />
                        </Button>

                        <span class="mx-1 h-5 w-px bg-border" />

                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.h1')"
                            @click="exec('formatBlock', 'H1')"
                        >
                            <Heading1 class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.h2')"
                            @click="exec('formatBlock', 'H2')"
                        >
                            <Heading2 class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.paragraph')"
                            @click="exec('formatBlock', 'P')"
                        >
                            <Type class="h-4 w-4" />
                        </Button>

                        <span class="mx-1 h-5 w-px bg-border" />

                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.link')"
                            @click="promptLink"
                        >
                            <LinkIcon class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8"
                            :title="t('documents.artifact_editor.image')"
                            @click="promptImage"
                        >
                            <Image class="h-4 w-4" />
                        </Button>

                        <span class="mx-1 h-5 w-px bg-border" />

                        <label
                            class="flex items-center gap-1 rounded px-1 text-xs"
                            :title="t('documents.artifact_editor.text_color')"
                        >
                            <Palette class="h-4 w-4" />
                            <input
                                v-model="textColor"
                                type="color"
                                class="h-6 w-6 cursor-pointer rounded border"
                                @change="applyTextColor"
                            />
                        </label>
                        <label
                            class="flex items-center gap-1 rounded px-1 text-xs"
                            :title="t('documents.artifact_editor.bg_color')"
                        >
                            <span class="h-4 w-4 rounded border bg-yellow-200" />
                            <input
                                v-model="bgColor"
                                type="color"
                                class="h-6 w-6 cursor-pointer rounded border"
                                @change="applyBgColor"
                            />
                        </label>
                    </div>

                    <iframe
                        ref="iframeRef"
                        sandbox="allow-same-origin"
                        class="min-h-0 w-full flex-1 rounded border bg-white"
                        :title="t('documents.artifact_editor.visual_tab')"
                    />
                    <p class="shrink-0 text-xs text-muted-foreground">
                        {{ t('documents.artifact_editor.scripts_hidden') }}
                    </p>
                </TabsContent>

                <TabsContent
                    value="code"
                    class="mt-3 flex min-h-0 flex-1 flex-col"
                >
                    <CodeMirrorEditor
                        v-model="source"
                        class="min-h-0 flex-1"
                    />
                </TabsContent>
            </Tabs>

            <DialogFooter>
                <Button variant="outline" :disabled="saving" @click="cancel">
                    {{ t('common.cancel') }}
                </Button>
                <Button :disabled="saving" @click="save">
                    {{ t('common.save') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
