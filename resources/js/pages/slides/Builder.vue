<script setup lang="ts">
import * as SlidesController from '@/actions/App/Http/Controllers/SlidesController';
import DeckSlide from '@/components/slides/DeckSlide.vue';
import SlideInspector from '@/components/slides/SlideInspector.vue';
import echo from '@/echo';
import {
    DECK_LAYOUTS,
    deckTheme,
    defaultSlide,
    type DeckBrand,
    type DeckLayout,
    type DeckManifest,
    type DeckSlideDef,
} from '@/lib/deck';
import { normalizeChatMarkdown } from '@/lib/markdown';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronLeft,
    ChevronRight,
    Copy,
    Download,
    Link2,
    Loader2,
    Play,
    Plus,
    Send,
    SlidersHorizontal,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import axios from 'axios';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * The Slide Builder: AI chat on the left, live deck canvas on the right —
 * the App-Builder pattern applied to presentations. Every edit path (chat,
 * inspector, toolbar ops) funnels through the same atomic DeckEditor on the
 * server, so the preview is always a render of a VALID deck.
 */
const props = defineProps<{
    deck: {
        id: string;
        name: string;
        manifest: DeckManifest;
        resolved: DeckManifest;
    };
    brand: DeckBrand;
    messages: { role: string; content: string; at?: string }[];
}>();

const { t } = useI18n();

// ----- deck state -----
const name = ref(props.deck.name);
const manifest = ref<DeckManifest>(props.deck.manifest);
const resolved = ref<DeckManifest>(props.deck.resolved);
const selected = ref(0);
const saving = ref(false);
const saveError = ref<string | null>(null);

const slides = computed(() => manifest.value.slides ?? []);
const previewSlides = computed(() => resolved.value.slides ?? []);
const tokens = computed(() =>
    deckTheme(manifest.value.theme, props.brand.accent),
);

const stageStyle = computed(() => ({
    '--deck-bg': tokens.value.bg,
    '--deck-surface': tokens.value.surface,
    '--deck-ink': tokens.value.ink,
    '--deck-muted': tokens.value.muted,
    '--deck-subtle': tokens.value.subtle,
    '--deck-line': tokens.value.line,
    '--deck-accent': tokens.value.series[0],
}));

// ----- direct edits (inspector / toolbar) → PATCH -----
type Op = { op: string; index: number; slide?: DeckSlideDef; to?: number };

async function applyOps(
    operations: Op[],
    extra: { name?: string; theme?: string } = {},
) {
    saving.value = true;
    saveError.value = null;
    try {
        const { data } = await axios.patch(
            SlidesController.update(props.deck.id).url,
            { operations, ...extra },
        );
        manifest.value = data.manifest;
        resolved.value = data.resolved;
        name.value = data.name;
    } catch (e: any) {
        saveError.value =
            e?.response?.data?.message ?? t('slides.builder.save_failed');
    } finally {
        saving.value = false;
    }
}

// Debounced inspector edits: replace the selected slide.
let inspectorTimer: ReturnType<typeof setTimeout> | null = null;
function onInspectorChange(slide: DeckSlideDef) {
    const index = selected.value;
    // Optimistic local update so typing feels instant.
    manifest.value = {
        ...manifest.value,
        slides: slides.value.map((sl, i) => (i === index ? slide : sl)),
    };
    if (inspectorTimer) clearTimeout(inspectorTimer);
    inspectorTimer = setTimeout(
        () => applyOps([{ op: 'replace', index, slide }]),
        600,
    );
}

function addSlide(layout: DeckLayout) {
    const index = selected.value + 1;
    applyOps([{ op: 'insert', index, slide: defaultSlide(layout) }]).then(
        () => (selected.value = index),
    );
    addMenuOpen.value = false;
}

function duplicateSlide() {
    const index = selected.value;
    const copy = JSON.parse(JSON.stringify(slides.value[index]));
    applyOps([{ op: 'insert', index: index + 1, slide: copy }]).then(
        () => (selected.value = index + 1),
    );
}

function removeSlide() {
    if (slides.value.length <= 1) return;
    if (!confirm(t('slides.builder.delete_slide_confirm'))) return;
    const index = selected.value;
    applyOps([{ op: 'remove', index }]).then(() => {
        selected.value = Math.min(index, slides.value.length - 1);
    });
}

function moveSlide(delta: number) {
    const from = selected.value;
    const to = from + delta;
    if (to < 0 || to >= slides.value.length) return;
    applyOps([{ op: 'move', index: from, to }]).then(
        () => (selected.value = to),
    );
}

function setTheme(theme: string) {
    applyOps([], { theme });
}

function renameDeck(value: string) {
    const trimmed = value.trim();
    if (trimmed === '' || trimmed === name.value) return;
    applyOps([], { name: trimmed });
}

// ----- AI chat -----
interface BuilderMsg {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    streaming?: boolean;
    error?: string | null;
}

const chat = ref<BuilderMsg[]>(
    (props.messages ?? []).map((m, i) => ({
        id: `h-${i}`,
        role: m.role === 'user' ? 'user' : 'assistant',
        content: m.content,
    })),
);
const draft = ref('');
const aiBusy = ref(false);
const chatScroller = ref<HTMLElement | null>(null);

function renderMarkdown(content: string): string {
    const raw = marked.parse(normalizeChatMarkdown(content), {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}

async function scrollChat() {
    await nextTick();
    chatScroller.value?.scrollTo({ top: chatScroller.value.scrollHeight });
}

async function send() {
    const content = draft.value.trim();
    if (content === '' || aiBusy.value) return;
    draft.value = '';
    aiBusy.value = true;
    chat.value.push({ id: `u-${Date.now()}`, role: 'user', content });
    scrollChat();
    try {
        const { data } = await axios.post(
            SlidesController.builderMessage(props.deck.id).url,
            { content },
        );
        chat.value.push({
            id: data.message_id,
            role: 'assistant',
            content: '',
            streaming: true,
        });
        scrollChat();
    } catch {
        aiBusy.value = false;
        chat.value.push({
            id: `e-${Date.now()}`,
            role: 'assistant',
            content: t('slides.builder.send_failed'),
            error: t('slides.builder.send_failed'),
        });
    }
}

let channel: ReturnType<typeof echo.private> | null = null;

function subscribe() {
    channel = echo.private(`slides.builder.${props.deck.id}`);
    channel.listen(
        '.SlideBuilderChunk',
        (data: { message_id: string; delta: string }) => {
            const msg = chat.value.find((m) => m.id === data.message_id);
            if (msg) {
                msg.content += data.delta;
            } else {
                chat.value.push({
                    id: data.message_id,
                    role: 'assistant',
                    content: data.delta,
                    streaming: true,
                });
            }
            scrollChat();
        },
    );
    channel.listen(
        '.SlideBuilderComplete',
        (data: {
            message_id: string;
            content: string;
            manifest: DeckManifest | null;
            resolved: DeckManifest | null;
            name: string | null;
        }) => {
            aiBusy.value = false;
            const msg = chat.value.find((m) => m.id === data.message_id);
            if (msg) {
                msg.content = data.content;
                msg.streaming = false;
            }
            if (data.manifest) {
                manifest.value = data.manifest;
                selected.value = Math.min(
                    selected.value,
                    (data.manifest.slides ?? []).length - 1,
                );
            }
            if (data.resolved) resolved.value = data.resolved;
            if (data.name) name.value = data.name;
            scrollChat();
        },
    );
    channel.listen(
        '.SlideBuilderError',
        (data: { message_id: string; message: string }) => {
            aiBusy.value = false;
            const msg = chat.value.find((m) => m.id === data.message_id);
            if (msg) {
                msg.streaming = false;
                msg.error = data.message;
                if (msg.content === '') msg.content = data.message;
            }
        },
    );
}

// ----- preview scaling -----
const previewBox = ref<HTMLElement | null>(null);
const previewScale = ref(0.4);
let resizeObserver: ResizeObserver | null = null;

function rescale() {
    const el = previewBox.value;
    if (!el) return;
    previewScale.value = Math.min(el.clientWidth / 1280, el.clientHeight / 720);
}

const inspectorOpen = ref(true);
const addMenuOpen = ref(false);

watch(inspectorOpen, () => nextTick(rescale));

onMounted(() => {
    subscribe();
    rescale();
    resizeObserver = new ResizeObserver(rescale);
    if (previewBox.value) resizeObserver.observe(previewBox.value);
    scrollChat();
});

onBeforeUnmount(() => {
    if (channel) echo.leave(`slides.builder.${props.deck.id}`);
    resizeObserver?.disconnect();
});

// ----- share / export -----
const shareCopied = ref(false);
async function shareDeck() {
    try {
        const { data } = await axios.post(
            SlidesController.share(props.deck.id).url,
        );
        await navigator.clipboard.writeText(data.url);
        shareCopied.value = true;
        setTimeout(() => (shareCopied.value = false), 2000);
    } catch {
        // no-op
    }
}
</script>

<template>
    <Head :title="`${name} · ${t('slides.builder.title')}`" />

    <div class="flex h-screen flex-col bg-navy-deep text-ink">
        <!-- Topbar -->
        <header
            class="flex h-14 shrink-0 items-center gap-3 border-b border-soft px-4"
        >
            <Link
                :href="SlidesController.index().url"
                class="rounded-lg p-2 text-ink-muted transition-colors hover:text-ink"
                :aria-label="t('slides.builder.back')"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <input
                :value="name"
                class="min-w-0 flex-1 truncate rounded-lg border border-transparent bg-transparent px-2 py-1 text-sm font-semibold text-ink outline-none hover:border-medium focus:border-strong"
                @change="renameDeck(($event.target as HTMLInputElement).value)"
            />
            <span
                v-if="saving"
                class="inline-flex items-center gap-1.5 text-xs text-ink-subtle"
            >
                <Loader2 class="size-3 animate-spin" />
                {{ t('slides.builder.saving') }}
            </span>
            <span v-else-if="saveError" class="text-xs text-sp-danger">
                {{ saveError }}
            </span>

            <select
                :value="manifest.theme ?? 'executive'"
                class="rounded-lg border border-medium bg-surface px-2 py-1.5 text-xs text-ink outline-none"
                @change="setTheme(($event.target as HTMLSelectElement).value)"
            >
                <option value="executive">Executive</option>
                <option value="dark">Dark</option>
            </select>

            <button
                type="button"
                class="toolbar-btn"
                :title="t('slides.present.share')"
                @click="shareDeck"
            >
                <Link2 v-if="!shareCopied" class="size-4" />
                <span v-else class="text-xs">✓</span>
            </button>
            <a
                :href="SlidesController.export(deck.id).url"
                class="toolbar-btn"
                :title="t('slides.present.download_pdf')"
            >
                <Download class="size-4" />
            </a>
            <a
                :href="SlidesController.present(deck.id).url"
                target="_blank"
                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
            >
                <Play class="size-3.5" />
                {{ t('slides.builder.present') }}
            </a>
        </header>

        <div class="flex min-h-0 flex-1">
            <!-- Left: AI chat -->
            <aside
                class="flex w-[380px] shrink-0 flex-col border-r border-soft"
            >
                <div
                    ref="chatScroller"
                    class="flex-1 space-y-4 overflow-y-auto px-4 py-4"
                >
                    <div
                        v-if="chat.length === 0"
                        class="rounded-xl border border-dashed border-soft p-4 text-xs leading-relaxed text-ink-muted"
                    >
                        <Sparkles class="mb-2 size-4 text-accent-blue" />
                        {{ t('slides.builder.chat_empty') }}
                    </div>
                    <div v-for="m in chat" :key="m.id">
                        <div
                            v-if="m.role === 'user'"
                            class="ml-8 rounded-2xl rounded-br-md bg-accent-blue/10 px-3.5 py-2.5 text-sm text-ink"
                        >
                            {{ m.content }}
                        </div>
                        <div v-else class="text-sm">
                            <div
                                v-if="m.content"
                                class="sp-chat-prose prose prose-sm max-w-none text-ink dark:prose-invert"
                                v-html="renderMarkdown(m.content)"
                            />
                            <div
                                v-if="m.streaming"
                                class="mt-1 inline-flex items-center gap-1.5 text-xs text-ink-subtle"
                            >
                                <Loader2 class="size-3 animate-spin" />
                                {{ t('slides.builder.thinking') }}
                            </div>
                            <p
                                v-if="m.error"
                                class="mt-1 text-xs text-sp-danger"
                            >
                                {{ m.error }}
                            </p>
                        </div>
                    </div>
                </div>
                <form
                    class="flex items-end gap-2 border-t border-soft p-3"
                    @submit.prevent="send"
                >
                    <textarea
                        v-model="draft"
                        rows="2"
                        class="min-h-0 flex-1 resize-none rounded-xl border border-medium bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-strong"
                        :placeholder="t('slides.builder.chat_placeholder')"
                        @keydown.enter.exact.prevent="send"
                    />
                    <button
                        type="submit"
                        class="rounded-xl bg-accent-blue p-2.5 text-white transition-colors hover:bg-accent-blue-hover disabled:opacity-40"
                        :disabled="aiBusy || draft.trim() === ''"
                    >
                        <Send class="size-4" />
                    </button>
                </form>
            </aside>

            <!-- Right: canvas -->
            <main class="flex min-w-0 flex-1 flex-col">
                <!-- Slide toolbar -->
                <div
                    class="flex h-11 shrink-0 items-center gap-1 border-b border-soft px-3"
                >
                    <span class="mr-2 text-xs text-ink-subtle">
                        {{
                            t('slides.builder.slide_of', {
                                n: selected + 1,
                                total: slides.length,
                            })
                        }}
                    </span>
                    <button
                        class="toolbar-btn"
                        :title="t('slides.builder.move_left')"
                        @click="moveSlide(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <button
                        class="toolbar-btn"
                        :title="t('slides.builder.move_right')"
                        @click="moveSlide(1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                    <button
                        class="toolbar-btn"
                        :title="t('slides.builder.duplicate')"
                        @click="duplicateSlide"
                    >
                        <Copy class="size-4" />
                    </button>
                    <button
                        class="toolbar-btn hover:!text-sp-danger"
                        :title="t('slides.builder.delete_slide')"
                        @click="removeSlide"
                    >
                        <Trash2 class="size-4" />
                    </button>
                    <div class="relative">
                        <button
                            class="toolbar-btn"
                            :title="t('slides.builder.add_slide')"
                            @click="addMenuOpen = !addMenuOpen"
                        >
                            <Plus class="size-4" />
                        </button>
                        <div
                            v-if="addMenuOpen"
                            class="absolute top-9 left-0 z-50 w-44 rounded-xl border border-medium bg-navy p-1 shadow-lg"
                        >
                            <button
                                v-for="layout in DECK_LAYOUTS"
                                :key="layout"
                                type="button"
                                class="block w-full rounded-lg px-3 py-1.5 text-left text-xs text-ink transition-colors hover:bg-surface"
                                @click="addSlide(layout)"
                            >
                                {{ t(`slides.layout.${layout}`) }}
                            </button>
                        </div>
                    </div>
                    <div class="ml-auto">
                        <button
                            class="toolbar-btn"
                            :class="{ '!text-accent-blue': inspectorOpen }"
                            :title="t('slides.builder.edit_slide')"
                            @click="inspectorOpen = !inspectorOpen"
                        >
                            <SlidersHorizontal class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="flex min-h-0 flex-1">
                    <!-- Preview -->
                    <div
                        ref="previewBox"
                        class="relative min-w-0 flex-1 overflow-hidden bg-black/20 p-6"
                        :style="stageStyle"
                    >
                        <div
                            class="absolute top-1/2 left-1/2 origin-center shadow-2xl"
                            :style="{
                                width: '1280px',
                                height: '720px',
                                background: 'var(--deck-bg)',
                                transform: `translate(-50%, -50%) scale(${previewScale * 0.92})`,
                            }"
                        >
                            <DeckSlide
                                v-if="previewSlides[selected]"
                                :slide="previewSlides[selected]"
                                :position="selected + 1"
                                :tokens="tokens"
                                :logo-url="brand.logo_url"
                            />
                        </div>
                    </div>

                    <!-- Inspector -->
                    <aside
                        v-if="inspectorOpen && slides[selected]"
                        class="w-[320px] shrink-0 overflow-y-auto border-l border-soft p-4"
                    >
                        <p
                            class="mb-3 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase"
                        >
                            {{ t(`slides.layout.${slides[selected].layout}`) }}
                        </p>
                        <SlideInspector
                            :key="`${selected}-${slides[selected].layout}`"
                            :slide="slides[selected]"
                            @change="onInspectorChange"
                        />
                    </aside>
                </div>

                <!-- Thumbnail rail -->
                <div
                    class="flex h-[104px] shrink-0 items-center gap-3 overflow-x-auto border-t border-soft px-4"
                    :style="stageStyle"
                >
                    <button
                        v-for="(slide, i) in previewSlides"
                        :key="i"
                        type="button"
                        class="relative shrink-0 overflow-hidden rounded-md transition-shadow"
                        :class="
                            i === selected
                                ? 'ring-2 ring-accent-blue'
                                : 'ring-1 ring-white/10 hover:ring-white/30'
                        "
                        :style="{ width: '128px', height: '72px' }"
                        @click="selected = i"
                    >
                        <div
                            class="pointer-events-none absolute top-0 left-0 origin-top-left"
                            :style="{
                                width: '1280px',
                                height: '720px',
                                background: 'var(--deck-bg)',
                                transform: 'scale(0.1)',
                            }"
                        >
                            <DeckSlide
                                :slide="slide"
                                :position="i + 1"
                                :tokens="tokens"
                                :logo-url="brand.logo_url"
                                :print-mode="true"
                            />
                        </div>
                        <span
                            class="absolute right-1 bottom-0.5 text-[9px] font-medium text-ink-subtle"
                        >
                            {{ i + 1 }}
                        </span>
                    </button>
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped>
.toolbar-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    padding: 6px;
    color: var(--sp-text-secondary);
    transition:
        color 0.15s ease,
        background 0.15s ease;
}
.toolbar-btn:hover {
    color: var(--sp-text-primary);
    background: var(--sp-surface);
}
</style>
