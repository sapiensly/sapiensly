<script setup lang="ts">
import * as AppController from '@/actions/App/Http/Controllers/AppController';
import AppAccessPanel from '@/components/apps/AppAccessPanel.vue';
import LayersExplorer from '@/components/apps/LayersExplorer.vue';
import SchemaView from '@/components/apps/SchemaView.vue';
import SlashCommandMenu from '@/components/apps/SlashCommandMenu.vue';
import WireframeImportDialog from '@/components/apps/WireframeImportDialog.vue';
import AppWorkflowsTab from '@/components/apps/workflows/AppWorkflowsTab.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import echo from '@/echo';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import {
    expandSlashCommand,
    matchingCommands,
    parseSlashInput,
    slashFilterFor,
    type SlashCommand,
} from '@/lib/builderSlashCommands';
import AppRenderer from '@/runtime/AppRenderer.vue';
import BlockBreadcrumb from '@/runtime/blocks/BlockBreadcrumb.vue';
import SiteFooter from '@/runtime/SiteFooter.vue';
import SiteHeader from '@/runtime/SiteHeader.vue';
import SiteSidebar from '@/runtime/SiteSidebar.vue';
import { runtimeSettingsStyle } from '@/runtime/runtimeStyle';
import { useSidebarCollapsed } from '@/runtime/useSidebarCollapsed';
import type {
    BlockData,
    ObjectDef,
    PageDef,
    PageSummary,
} from '@/runtime/types/manifest';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { toast } from 'vue-sonner';
// html2canvas-pro is a maintained fork that handles modern CSS color
// functions (oklch, color-mix) that Tailwind v4 emits. The vanilla
// html2canvas chokes with "Attempting to parse an unsupported color
// function 'oklch'" the moment any Tailwind utility lands in the snapshot.
import DOMPurify from 'dompurify';
// html2canvas-pro handles modern CSS color functions (oklch, color-mix) that
// Tailwind v4 emits. The vanilla html2canvas chokes the moment any Tailwind
// utility lands in the snapshot.
import BuilderIntegrationCard, {
    type IntegrationProposal,
} from '@/components/apps/builder/BuilderIntegrationCard.vue';
import BuilderPlanCard, {
    type Plan,
} from '@/components/apps/builder/BuilderPlanCard.vue';
import {
    ArrowLeft,
    BarChart3,
    Camera,
    Check,
    ChevronDown,
    Code,
    Database,
    Download,
    Eye,
    FileText,
    GripVertical,
    ImagePlus,
    Layers,
    LayoutDashboard,
    Lightbulb,
    Link2,
    ListChecks,
    Loader2,
    Maximize2,
    Minimize2,
    MoreVertical,
    MousePointerClick,
    PanelLeftClose,
    PanelLeftOpen,
    Paperclip,
    Plus,
    RotateCcw,
    Send,
    Settings2,
    ShieldCheck,
    Sparkles,
    Wand2,
    Workflow as WorkflowIcon,
    X,
} from '@lucide/vue';
import html2canvas from 'html2canvas-pro';
import { marked } from 'marked';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    provide,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';

interface Message {
    id: string;
    role: 'user' | 'assistant';
    content: string | null;
    proposed_patch: Array<Record<string, unknown>> | null;
    change_summary: string | null;
    plan: Plan | null;
    integration_proposal: IntegrationProposal | null;
    status:
        | 'pending'
        | 'applied'
        | 'rejected'
        | 'reverted'
        | 'none'
        | 'streaming';
    applied_version_id: string | null;
    attachment_url: string | null;
    attachment_mime: string | null;
    created_at: string | null;
}

interface Preview {
    page: PageDef;
    pages: PageSummary[];
    block_data: BlockData;
    objects: ObjectDef[];
    settings: { default_locale?: string; default_currency?: string };
    custom_css?: string;
}

interface SchemaPayload {
    objects: Array<
        ObjectDef & {
            system_fields?: Array<{
                id: string;
                slug: string;
                name: string;
                type: string;
            }>;
        }
    >;
    record_counts: Record<string, number>;
    workflows_by_object: Record<
        string,
        Array<{ id: string; name: string; trigger_type: string | null }>
    >;
}

interface Props {
    app: { id: string; slug: string; name: string; description: string | null };
    manifest: Record<string, unknown> | null;
    preview: Preview | null;
    schema: SchemaPayload | null;
    conversation: { id: string; messages: Message[] };
    brand?: {
        primary_color: string | null;
        font: string | null;
        theme: string | null;
        logo_url: string | null;
    } | null;
    models?: Array<{ id: string; label: string }>;
    defaultModel?: string;
    versions?: Array<{
        id: string;
        version: number;
        summary: string | null;
        created_at: string | null;
        current: boolean;
    }>;
}

const props = defineProps<Props>();

// Model the AI uses for this conversation's turns. Defaults to the cheap/fast
// model; the user can pick a stronger one for creative builds (e.g. websites).
const selectedModel = ref<string>(
    props.defaultModel ?? props.models?.[0]?.id ?? '',
);

// Human label of the currently-selected model, for the gear button's tooltip.
const selectedModelLabel = computed(
    () =>
        props.models?.find((m) => m.id === selectedModel.value)?.label ??
        selectedModel.value,
);

// Pull the raw locale dicts and current UI locale so we can render chips in
// the language the user is currently chatting in — which may differ from
// the UI's default locale (e.g. UI in en, user is writing in es).
const { t, messages: i18nMessages, locale: uiLocale } = useI18n();

const messages = ref<Message[]>(props.conversation.messages);
const input = ref('');
const inputEl = ref<HTMLTextAreaElement | null>(null);
const sending = ref(false);
const errorText = ref<string | null>(null);
const transcript = ref<HTMLElement | null>(null);
const previewPane = ref<HTMLElement | null>(null);
const requestingReview = ref(false);
const wireframeOpen = ref(false);
const layersOpen = ref(false);

// ---------- Live activity feedback (what the model is doing right now) ----------
interface Activity {
    phase: string;
    model: string | null;
    tool: string | null;
}
const liveActivity = ref<Activity | null>(null);
const liveSteps = ref<Array<{ tool: string; label: string }>>([]);

// Friendly labels for the builder tools, so the status reads as plain language
// ("Testing a query") rather than the raw tool name ("simulate_query").
const TOOL_LABELS: Record<string, string> = {
    read_manifest: 'Reading the app',
    list_available_components: 'Checking the catalog',
    list_available_field_types: 'Checking the catalog',
    list_available_actions: 'Checking the catalog',
    list_available_triggers: 'Checking the catalog',
    list_available_steps: 'Checking the catalog',
    inspect_records: 'Inspecting records',
    simulate_query: 'Testing a query',
    validate_manifest: 'Validating',
    propose_change: 'Proposing a change',
    delete_block_by_id: 'Removing a block',
    seed_records: 'Adding sample data',
    discover_integration: 'Finding the connection',
    create_integration: 'Setting up the connection',
    test_connection: 'Testing the connection',
    sample_endpoint: 'Sampling the endpoint',
};
function toolLabel(name: string): string {
    return (
        TOOL_LABELS[name] ??
        name.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    );
}
function modelLabel(id: string | null | undefined): string {
    if (!id) {
        return '';
    }
    return props.models?.find((m) => m.id === id)?.label ?? id;
}

// The model shown in the live status — the one actually running this turn when
// known, else the user's selection.
const activityModelLabel = computed(
    () => modelLabel(liveActivity.value?.model) || selectedModelLabel.value,
);

// One-line description of the current phase for the status bar.
const activityStatus = computed(() => {
    const a = liveActivity.value;
    if (a === null) {
        return sending.value ? 'Working…' : 'Ready';
    }
    if (a.phase === 'tool') {
        return toolLabel(a.tool ?? '') + '…';
    }
    if (a.phase === 'writing') {
        return 'Writing the reply…';
    }
    return 'Thinking…';
});
const isBusy = computed(() => sending.value || liveActivity.value !== null);

// ---------- Real fullscreen for left/right panes ----------
// When toggled, the chosen pane becomes a fixed-position overlay covering
// the whole viewport (hiding the layout's sidebar/topbar). We also try the
// browser Fullscreen API to push the URL bar / tabs out of view too —
// best-effort; failures (e.g. browser refuses on permission policy) are
// swallowed because the fixed overlay alone already feels fullscreen.
//
// The non-active pane gets `v-show`-hidden so its state (transcript
// scroll, in-flight stream, workflow draft graph, etc) survives the
// round-trip. ESC exits.
type FullscreenPanel = 'chat' | 'work' | null;
const fullscreenPanel = ref<FullscreenPanel>(null);

const chatSection = ref<HTMLElement | null>(null);
const workSection = ref<HTMLElement | null>(null);

// ---------- Draggable panel resizer ----------
// On large screens the two panes sit side by side. A thin grab-handle column
// between them lets the user drag the split left/right, trading width between
// chat and work. The chosen width (px of the left/chat pane) persists in
// localStorage so it survives reloads. On mobile (single column) and while a
// pane is fullscreen the handle is hidden and the layout falls back to the
// stacked / single-column form.
const DEFAULT_CHAT_WIDTH = 420;
const MIN_CHAT_WIDTH = 320;
const MIN_WORK_WIDTH = 420;
const RESIZER_WIDTH = 12; // px — must match the middle grid track below.
const CHAT_WIDTH_STORAGE_KEY = 'builder.chatWidth';

const gridEl = ref<HTMLElement | null>(null);
const chatWidth = ref(DEFAULT_CHAT_WIDTH);
const isLargeScreen = ref(true);
const isResizing = ref(false);

// The resizer only makes sense when both panes are visible side by side.
const resizable = computed(
    () => fullscreenPanel.value === null && isLargeScreen.value,
);

const gridClass = computed(() => {
    if (fullscreenPanel.value !== null) {
        // When fullscreen, the grid container becomes irrelevant since the
        // active pane is `position: fixed`. We still keep grid-cols-1 so
        // there's no leftover whitespace where the other pane used to be.
        return 'grid min-h-0 flex-1 grid-cols-1 gap-4';
    }
    // On large screens the explicit gridTemplateColumns (chat / resizer / work)
    // comes from gridStyle and the middle track supplies the gutter, so no
    // gap. On mobile we stack with a gap.
    return isLargeScreen.value
        ? 'grid min-h-0 flex-1 grid-cols-1'
        : 'grid min-h-0 flex-1 grid-cols-1 gap-4';
});

const gridStyle = computed(() => {
    if (!resizable.value) {
        return {};
    }
    return {
        gridTemplateColumns: `${chatWidth.value}px ${RESIZER_WIDTH}px minmax(0, 1fr)`,
    };
});

/** Clamp a candidate chat width to the container's current bounds. */
function clampChatWidth(width: number): number {
    const containerWidth = gridEl.value?.clientWidth ?? window.innerWidth;
    const maxChat = Math.max(
        MIN_CHAT_WIDTH,
        containerWidth - MIN_WORK_WIDTH - RESIZER_WIDTH,
    );
    return Math.round(Math.min(Math.max(width, MIN_CHAT_WIDTH), maxChat));
}

function persistChatWidth() {
    try {
        localStorage.setItem(CHAT_WIDTH_STORAGE_KEY, String(chatWidth.value));
    } catch {
        // localStorage can throw in private mode / when disabled — non-fatal,
        // the width just won't persist across reloads.
    }
}

function startResize(event: PointerEvent) {
    if (!resizable.value) {
        return;
    }
    event.preventDefault();
    isResizing.value = true;

    const startX = event.clientX;
    const startWidth = chatWidth.value;

    function onMove(e: PointerEvent) {
        chatWidth.value = clampChatWidth(startWidth + (e.clientX - startX));
    }
    function onUp() {
        isResizing.value = false;
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        document.body.style.userSelect = '';
        document.body.style.cursor = '';
        persistChatWidth();
    }

    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    // Suppress text selection + force the col-resize cursor for the whole
    // drag, even when the pointer outruns the thin handle.
    document.body.style.userSelect = 'none';
    document.body.style.cursor = 'col-resize';
}

/** Keyboard a11y: arrow keys nudge the split, home/end jump to extremes. */
function onResizerKeydown(event: KeyboardEvent) {
    if (!resizable.value) {
        return;
    }
    const step = event.shiftKey ? 48 : 16;
    if (event.key === 'ArrowLeft') {
        chatWidth.value = clampChatWidth(chatWidth.value - step);
    } else if (event.key === 'ArrowRight') {
        chatWidth.value = clampChatWidth(chatWidth.value + step);
    } else if (event.key === 'Home') {
        chatWidth.value = clampChatWidth(MIN_CHAT_WIDTH);
    } else if (event.key === 'End') {
        chatWidth.value = clampChatWidth(Number.MAX_SAFE_INTEGER);
    } else {
        return;
    }
    event.preventDefault();
    persistChatWidth();
}

/** Double-click the handle to snap back to the default split. */
function resetResize() {
    chatWidth.value = clampChatWidth(DEFAULT_CHAT_WIDTH);
    persistChatWidth();
}

function onScreenChange(matches: boolean) {
    isLargeScreen.value = matches;
}

let mediaQuery: MediaQueryList | null = null;
function handleMediaChange(e: MediaQueryListEvent) {
    onScreenChange(e.matches);
}

/**
 * Classes appended to a panel section when it's the active fullscreen
 * target. Removes its grid-tile chrome (rounded corners, border) and
 * pins it to the viewport.
 */
function fullscreenClassFor(panel: 'chat' | 'work'): string[] {
    if (fullscreenPanel.value !== panel) return [];
    return [
        'fixed',
        'inset-0',
        'z-50',
        '!rounded-none',
        '!border-0',
        'h-screen',
        'w-screen',
    ];
}

async function toggleFullscreen(panel: 'chat' | 'work') {
    const wasFullscreen = fullscreenPanel.value === panel;
    fullscreenPanel.value = wasFullscreen ? null : panel;

    // Best-effort: also flip the browser's native fullscreen so URL bar
    // and tabs are hidden. Skip silently if the browser refuses (some
    // contexts disable this via permission policy).
    try {
        if (wasFullscreen) {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            }
        } else {
            const el = panel === 'chat' ? chatSection.value : workSection.value;
            if (el && !document.fullscreenElement) {
                await el.requestFullscreen();
            }
        }
    } catch {
        // Browser refused — that's fine, the CSS overlay still covers
        // the app shell which is the main goal.
    }
}

function onWindowKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && fullscreenPanel.value !== null) {
        // Don't steal Escape from the slash menu — it has its own handler
        // that runs before this one (onComposerKeydown intercepts in the
        // input). Only act when the menu is closed.
        if (!slashMenuOpen.value) {
            fullscreenPanel.value = null;
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
        }
    }
}

/**
 * If the user exits the browser's native fullscreen via F11 / Esc / the
 * browser's own control, sync our state so the overlay disappears too.
 * Otherwise the user would end up in a weird "still overlaid but browser
 * chrome back" state.
 */
function onNativeFullscreenChange() {
    if (!document.fullscreenElement && fullscreenPanel.value !== null) {
        fullscreenPanel.value = null;
    }
}

// ---------- Slash command menu ----------
// Opens when the composer's input starts with "/". The list is filtered
// against whatever the user typed after the slash, and arrow-keys + Enter
// pick a command. Selecting a command pre-fills the input with
// `/name ` so the user can type its args (if any). On submit, the input
// is expanded into a fully-formed prompt before being sent.
const slashHighlightedIndex = ref(0);

const slashFilterText = computed(() => slashFilterFor(input.value));
const slashMenuOpen = computed(() => slashFilterText.value !== null);
const slashMatches = computed<SlashCommand[]>(() =>
    slashFilterText.value === null
        ? []
        : matchingCommands(slashFilterText.value),
);

// Keep the highlight in range when the filter narrows. Resetting to 0 on
// open also means the first matching command is always the default Enter
// target, which is what you want after typing "/" then a few chars.
watch(slashMatches, (matches) => {
    if (matches.length === 0) {
        slashHighlightedIndex.value = 0;
        return;
    }
    if (slashHighlightedIndex.value >= matches.length) {
        slashHighlightedIndex.value = matches.length - 1;
    }
});
watch(slashMenuOpen, (isOpen) => {
    if (isOpen) slashHighlightedIndex.value = 0;
});

function moveSlashHighlight(delta: number) {
    const len = slashMatches.value.length;
    if (len === 0) return;
    slashHighlightedIndex.value =
        (slashHighlightedIndex.value + delta + len) % len;
}

/** Apply the currently-highlighted command — prefills `/name ` in the input. */
function selectSlashCommand(cmd: SlashCommand) {
    input.value = '/' + cmd.name + ' ';
    nextTick(() => {
        inputEl.value?.focus();
        const len = inputEl.value?.value.length ?? 0;
        inputEl.value?.setSelectionRange(len, len);
    });
}

function onComposerKeydown(event: KeyboardEvent) {
    if (slashMenuOpen.value) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveSlashHighlight(1);
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveSlashHighlight(-1);
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            // Clear just the slash so the menu closes but the user doesn't lose
            // anything they were going to type.
            if (input.value.startsWith('/')) {
                input.value = '';
            }
            return;
        }
        if (
            event.key === 'Tab' ||
            (event.key === 'Enter' &&
                slashMatches.value.length > 0 &&
                parseSlashInput(input.value) === null)
        ) {
            // Enter selects from the menu only while the input is still just
            // a prefix (e.g. "/se" before becoming "/seed clientes 50"). Once
            // the user has typed enough that parseSlashInput recognises a full
            // command, Enter falls through to the submit path below.
            event.preventDefault();
            const match = slashMatches.value[slashHighlightedIndex.value];
            if (match) selectSlashCommand(match);
            return;
        }
    }

    // Textarea submit semantics: Enter sends, Shift+Enter inserts a newline.
    // Guard against IME composition so committing a candidate doesn't fire a
    // turn (relevant for accented input / Asian keyboards).
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

/**
 * Callback the wireframe import dialog fires after a successful POST. The
 * endpoint returns the refreshed message list (user turn + streaming
 * placeholder) just like sendMessage does, so we mirror that handling.
 */
function onWireframeImported(payload: {
    messages: unknown[];
    latest_message_id?: string;
}) {
    messages.value = payload.messages as Message[];
}
type ViewMode = 'preview' | 'schema' | 'workflows' | 'access' | 'manifest';
const viewMode = ref<ViewMode>('preview');

// ---------- Chat attachments ----------
// The user can stage one image for the next turn — picked via paperclip,
// pasted from the clipboard, or dropped on the chat pane. Showing it as a
// thumbnail in the composer keeps the affordance reversible (X to remove)
// before the user commits with Send.
const attachedFile = ref<File | null>(null);
const attachedPreviewUrl = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const isDraggingOver = ref(false);
const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024; // 5 MB — matches the controller cap.
const ATTACHMENT_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

function setAttachment(file: File | null) {
    // Clean up the previous blob URL so we don't leak between selections.
    if (attachedPreviewUrl.value) {
        URL.revokeObjectURL(attachedPreviewUrl.value);
        attachedPreviewUrl.value = null;
    }
    attachedFile.value = file;
    if (file) {
        attachedPreviewUrl.value = URL.createObjectURL(file);
    }
}

function pickAttachment() {
    fileInput.value?.click();
}

function onAttachmentChange(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    if (file) {
        acceptIncomingFile(file);
    }
    // Reset so picking the same file twice still triggers change.
    target.value = '';
}

function clearAttachment() {
    setAttachment(null);
}

/**
 * Common gate for any file entering the composer (input, paste, drop).
 * Validates MIME + size and sets the staged attachment on success;
 * otherwise surfaces a friendly error.
 */
function acceptIncomingFile(file: File): boolean {
    if (!ATTACHMENT_MIMES.includes(file.type)) {
        errorText.value = t('apps.builder.attachment_invalid_type');
        return false;
    }
    if (file.size > ATTACHMENT_MAX_BYTES) {
        errorText.value = t('apps.builder.attachment_too_large');
        return false;
    }
    errorText.value = null;
    setAttachment(file);
    return true;
}

function onPaste(event: ClipboardEvent) {
    if (!event.clipboardData) return;
    for (const item of Array.from(event.clipboardData.items)) {
        if (item.kind === 'file') {
            const file = item.getAsFile();
            if (file && acceptIncomingFile(file)) {
                event.preventDefault();
                return;
            }
        }
    }
}

function onDragOver(event: DragEvent) {
    if (!event.dataTransfer) return;
    // Only react when the drag contains files — otherwise we'd flicker the
    // overlay every time the user drags text inside the chat.
    if (Array.from(event.dataTransfer.items).some((i) => i.kind === 'file')) {
        event.preventDefault();
        isDraggingOver.value = true;
    }
}

function onDragLeave(event: DragEvent) {
    // Only end the overlay when we leave the chat container itself, not
    // children — relatedTarget being null means the pointer exited the pane.
    if (
        !event.relatedTarget ||
        !(event.currentTarget as Node).contains(event.relatedTarget as Node)
    ) {
        isDraggingOver.value = false;
    }
}

function onDrop(event: DragEvent) {
    event.preventDefault();
    isDraggingOver.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (file) {
        acceptIncomingFile(file);
    }
}

// ---------- Contextual chip suggestions ----------
// A small set of "starter prompts" that the user can click to pre-fill the
// composer with a well-formed question for Claude. Picks vary with the
// current view (preview vs. schema vs. manifest) and the actual content
// state (no objects yet, page with no blocks yet, etc.) so the suggestions
// always match what's in front of the user. Clicking a chip fills the
// input — it does NOT auto-send — so the user can always tweak before
// hitting Enter.
//
// Locale follows the conversation, not the UI: if the user is chatting in
// Spanish but their UI is set to English (or vice-versa), the chips swap
// to the language of the latest user message so the pre-filled prompt
// matches what Claude is replying in.

type Component = typeof Sparkles;

interface ChipSuggestion {
    id: string;
    label: string;
    prompt: string;
    icon: Component;
}

const SUPPORTED_CHIP_LOCALES = ['es', 'en'] as const;
type ChipLocale = (typeof SUPPORTED_CHIP_LOCALES)[number];

/**
 * Lightweight es/en detector: count common stopwords plus look for
 * Spanish-specific characters. Cheap, no library needed. Returns null when
 * neither side has any signal so the caller can fall back to UI locale.
 */
function detectChipLocale(text: string): ChipLocale | null {
    if (!text) return null;
    const lower = text.toLowerCase();

    // Spanish-specific glyphs are an instant tell — English doesn't use any.
    if (/[ñ¿¡áéíóú]/.test(lower)) return 'es';

    const tokens = lower.match(/[a-záéíóúñ]+/g) ?? [];
    if (tokens.length === 0) return null;

    const esWords = new Set([
        'el',
        'la',
        'los',
        'las',
        'un',
        'una',
        'de',
        'que',
        'y',
        'es',
        'para',
        'con',
        'por',
        'qué',
        'cómo',
        'cuándo',
        'dónde',
        'hola',
        'gracias',
        'o',
        'en',
        'no',
        'sí',
        'este',
        'esta',
        'ese',
        'esa',
        'quiero',
        'agregar',
        'tengo',
        'tienes',
        'puedo',
        'soy',
        'pero',
        'también',
    ]);
    const enWords = new Set([
        'the',
        'and',
        'or',
        'is',
        'are',
        'to',
        'of',
        'a',
        'an',
        'that',
        'what',
        'how',
        'when',
        'where',
        'hi',
        'hello',
        'thanks',
        'i',
        'you',
        'my',
        'your',
        'want',
        'add',
        'have',
        'has',
        'can',
        'with',
        'for',
        'on',
        'in',
        'this',
        'these',
        'them',
        'but',
        'also',
    ]);

    let esCount = 0;
    let enCount = 0;
    for (const tok of tokens) {
        if (esWords.has(tok)) esCount++;
        if (enWords.has(tok)) enCount++;
    }
    if (esCount === 0 && enCount === 0) return null;
    return esCount > enCount ? 'es' : 'en';
}

const conversationLocale = computed<ChipLocale>(() => {
    // Walk the conversation from newest to oldest and grab the first user
    // turn we can confidently classify. Falling back to UI locale only
    // when the conversation has nothing useful to look at.
    for (let i = messages.value.length - 1; i >= 0; i--) {
        const m = messages.value[i];
        if (m.role !== 'user' || !m.content) continue;
        const detected = detectChipLocale(m.content);
        if (detected) return detected;
    }
    return SUPPORTED_CHIP_LOCALES.includes(uiLocale.value as ChipLocale)
        ? (uiLocale.value as ChipLocale)
        : 'en';
});

/**
 * Read a string in the conversation's locale, falling back to the UI locale
 * if the key isn't translated. Used by chips, slash commands and any other
 * surface that wants to render in the language the user is chatting in
 * rather than the language vue-i18n is currently bound to.
 *
 * Our locale files are FLAT (keys are dotted strings), so the dict lookup
 * is a single indexer — no path walking needed.
 */
function tInConversationLocale(fullKey: string): string {
    const dict = i18nMessages.value[conversationLocale.value] as
        | Record<string, unknown>
        | undefined;
    const val = dict?.[fullKey];
    if (typeof val === 'string') return val;
    // Better a string in the "wrong" language than an opaque i18n key
    // visible to the user.
    return t(fullKey);
}

function chipText(baseKey: string, suffix: 'label' | 'prompt'): string {
    return tInConversationLocale(`${baseKey}.${suffix}`);
}

const chipSuggestions = computed<ChipSuggestion[]>(() => {
    const objects = props.schema?.objects ?? [];
    const blocks = props.preview?.page?.blocks ?? [];
    const pages = props.preview?.pages ?? [];
    const hasObjects = objects.length > 0;
    const hasPages = pages.length > 0;
    const hasBlocksOnCurrentPage = blocks.length > 0;

    // First turn of a brand-new app: focus on getting started.
    if (!hasObjects && messages.value.length === 0) {
        return [
            chip(
                'app-from-scratch',
                'apps.builder.chip.app_from_scratch',
                Sparkles,
            ),
            chip('first-object', 'apps.builder.chip.first_object', Database),
            chip(
                'suggest-objects',
                'apps.builder.chip.suggest_objects',
                Lightbulb,
            ),
        ];
    }

    if (viewMode.value === 'schema') {
        if (!hasObjects) {
            return [
                chip(
                    'first-object',
                    'apps.builder.chip.first_object',
                    Database,
                ),
                chip(
                    'suggest-objects',
                    'apps.builder.chip.suggest_objects',
                    Lightbulb,
                ),
            ];
        }
        return [
            chip('add-relation', 'apps.builder.chip.add_relation', Link2),
            chip('add-field', 'apps.builder.chip.add_field', Plus),
            chip(
                'add-validations',
                'apps.builder.chip.add_validations',
                ListChecks,
            ),
            chip('seed-data', 'apps.builder.chip.seed_data', Wand2),
        ];
    }

    if (viewMode.value === 'manifest') {
        return [
            chip(
                'explain-manifest',
                'apps.builder.chip.explain_manifest',
                FileText,
            ),
            chip('find-orphans', 'apps.builder.chip.find_orphans', ListChecks),
            chip('optimize', 'apps.builder.chip.optimize', Wand2),
        ];
    }

    // Preview tab — chip set depends on whether this page has any blocks yet.
    if (!hasPages || !hasBlocksOnCurrentPage) {
        return [
            chip(
                'empty-table',
                'apps.builder.chip.empty_page.add_table',
                Database,
            ),
            chip(
                'empty-dashboard',
                'apps.builder.chip.empty_page.add_dashboard',
                LayoutDashboard,
            ),
            chip('empty-form', 'apps.builder.chip.empty_page.add_form', Plus),
            chip('seed-data', 'apps.builder.chip.seed_data', Wand2),
        ];
    }

    return [
        chip('add-chart', 'apps.builder.chip.add_chart', BarChart3),
        chip(
            'add-action-column',
            'apps.builder.chip.add_action_column',
            MousePointerClick,
        ),
        chip('improve-layout', 'apps.builder.chip.improve_layout', Wand2),
        chip('add-workflow', 'apps.builder.chip.add_workflow', WorkflowIcon),
    ];
});

/** Build a ChipSuggestion from an i18n key root (`.label` + `.prompt`). */
function chip(id: string, baseKey: string, icon: Component): ChipSuggestion {
    return {
        id,
        label: chipText(baseKey, 'label'),
        prompt: chipText(baseKey, 'prompt'),
        icon,
    };
}

// Whether the suggestions menu has anything to offer right now. The chips now
// live behind an icon button, so we no longer hide them while typing — the
// menu is opt-in. We still suppress it mid-stream / while sending / when the
// slash menu owns the keyboard.
const hasSuggestions = computed(
    () =>
        chipSuggestions.value.length > 0 &&
        !aiIsThinking.value &&
        !sending.value &&
        !slashMenuOpen.value,
);

function applyChip(suggestion: ChipSuggestion) {
    input.value = suggestion.prompt;
    // Focus the textarea so the user can tweak or just hit Enter to send.
    nextTick(() => {
        inputEl.value?.focus();
        // Cursor at end of pre-filled text — easier to append a note than
        // edit the middle.
        const len = inputEl.value?.value.length ?? 0;
        inputEl.value?.setSelectionRange(len, len);
    });
}

// Drives the right-pane header (icon + uppercase heading) so it always
// matches whatever the tab selector is showing.
const currentViewMeta = computed(() => {
    const map = {
        preview: { icon: Eye, heading: t('apps.builder.preview_heading') },
        schema: { icon: Database, heading: t('apps.builder.schema_heading') },
        workflows: {
            icon: WorkflowIcon,
            heading: t('apps.builder.workflows_heading'),
        },
        access: {
            icon: ShieldCheck,
            heading: t('apps.builder.access_heading'),
        },
        manifest: { icon: Code, heading: t('apps.builder.manifest_heading') },
    } as const;
    return map[viewMode.value];
});

const conversationId = computed(() => props.conversation.id);

const previewLocale = computed(
    () => props.preview?.settings.default_locale ?? 'es-MX',
);
const previewCurrency = computed(
    () => props.preview?.settings.default_currency ?? 'MXN',
);
const previewTheme = computed<'light' | 'dark'>(
    () =>
        (props.preview?.settings as { theme?: 'light' | 'dark' } | undefined)
            ?.theme ?? 'light',
);

// Brand / footer / accent+font for the previewed site (mirrors the runtime page).
const previewSettings = computed(
    () => (props.preview?.settings ?? {}) as Record<string, unknown>,
);
const previewBrand = computed(() => ({
    name: props.app.name,
    ...((previewSettings.value.brand as object) ?? {}),
}));
// Shared with the embedded SiteSidebar so the title-bar toggle collapses it.
const previewSidebarCollapsed = useSidebarCollapsed();

// Mirror the runtime's chrome layout in the preview.
const previewSidebar = computed(
    () =>
        (previewSettings.value as { navigation_layout?: string })
            .navigation_layout === 'sidebar',
);
const previewNavItems = computed(
    () =>
        (props.manifest?.navigation as { items?: unknown[] } | null)?.items ??
        undefined,
);
// The breadcrumb lifts into the title band (above the page title); only in the
// sidebar layout, mirroring the runtime.
const previewBreadcrumbBlock = computed(() => {
    if (!previewSidebar.value) {
        return null;
    }
    const blocks = (props.preview?.page?.blocks ?? []) as Array<
        Record<string, unknown>
    >;
    return blocks.find((b) => b.type === 'breadcrumb') ?? null;
});
// The band owns both the breadcrumb and the page title; lift the breadcrumb out
// and drop a leading heading that repeats the page name so it never doubles.
const previewContentBlocks = computed(() => {
    let blocks = (props.preview?.page?.blocks ?? []) as Array<
        Record<string, unknown>
    >;
    const name =
        (props.preview?.page as { name?: string } | undefined)?.name ?? '';
    if (previewSidebar.value) {
        blocks = blocks.filter((b) => b.type !== 'breadcrumb');
    }
    if (
        blocks[0]?.type === 'heading' &&
        String(blocks[0].content ?? '')
            .trim()
            .toLowerCase() === String(name).trim().toLowerCase()
    ) {
        return blocks.slice(1);
    }
    return blocks;
});
const previewFooter = computed(
    () =>
        previewSettings.value.footer as
            | { text?: string; links?: { label: string; href: string }[] }
            | undefined,
);
// Live accent override from the design control — applied to the preview
// immediately and debounce-persisted to settings.accent.
const accentOverride = ref<string | null>(null);
const effectiveAccent = computed(
    () =>
        accentOverride.value ??
        (previewSettings.value.accent as string | undefined) ??
        null,
);
const previewSurfaceStyle = computed(() => ({
    '--sp-bleed': '1.25rem',
    ...runtimeSettingsStyle({
        accent: effectiveAccent.value ?? undefined,
        font: previewSettings.value.font as string | undefined,
    }),
}));

const ACCENT_PRESETS = [
    '#0096ff',
    '#6366f1',
    '#8b5cf6',
    '#ec4899',
    '#ef4444',
    '#f59e0b',
    '#10b981',
    '#14b8a6',
];
let accentSaveTimer: ReturnType<typeof setTimeout> | null = null;
function setAccent(hex: string) {
    accentOverride.value = hex; // instant preview
    if (accentSaveTimer) {
        clearTimeout(accentSaveTimer);
    }
    // Debounce: dragging the colour picker would otherwise save a version per tick.
    accentSaveTimer = setTimeout(() => {
        axios
            .post(`/apps/${props.app.id}/builder/design`, { accent: hex })
            .catch(() => toast.error('No se pudo guardar el color de acento.'));
    }, 500);
}

// "Use organization brand": apply the org Brandbook's accent/font/theme to this
// app via the design endpoint (a new version), so the design panel can adopt the
// central brand in one click. Disabled when the org has no brand to apply.
const hasOrgBrand = computed(
    () =>
        !!props.brand &&
        !!(
            props.brand.primary_color ||
            props.brand.font ||
            props.brand.theme ||
            props.brand.logo_url
        ),
);
function useOrgBrand() {
    if (!props.brand) return;
    const payload: Record<string, string> = {};
    if (props.brand.primary_color) payload.accent = props.brand.primary_color;
    if (props.brand.font) payload.font = props.brand.font;
    if (props.brand.theme) payload.theme = props.brand.theme;
    if (Object.keys(payload).length === 0) return;

    if (payload.accent) accentOverride.value = payload.accent; // instant preview
    axios
        .post(`/apps/${props.app.id}/builder/design`, payload)
        .then(() =>
            router.reload({
                only: ['preview', 'manifest'],
                preserveScroll: true,
            }),
        )
        .catch(() => toast.error(t('apps.builder.brand_apply_failed')));
}

// Provide the App slug for BlockForm/BlockButton inside the preview so any
// action they fire goes to /r/{slug}/actions just like in the real runtime.
provide('appSlug', props.app.slug);

// Re-sync the local ref whenever Inertia gives us new props (conversation
// switched, page reloaded after an approve). Jump to the bottom without
// animation so the user lands on the latest reply.
watch(
    () => props.conversation.messages,
    (newMessages) => {
        messages.value = newMessages;
        nextTick(() => scrollToBottom('instant'));
    },
);

watch(messages, async () => {
    await nextTick();
    scrollToBottom('smooth');
});

function scrollToBottom(behavior: ScrollBehavior) {
    transcript.value?.scrollTo({
        top: transcript.value.scrollHeight,
        behavior,
    });
}

// While a placeholder is still streaming, the input shows a 'thinking' state
// — the user can't fire another turn until Claude finishes the current one.
const aiIsThinking = computed(() =>
    messages.value.some(
        (m) => m.role === 'assistant' && m.status === 'streaming',
    ),
);

async function send() {
    let text = input.value.trim();
    // Slash-command expansion: if the input is a recognised /command with
    // its required args present, swap the raw text for the full prompt so
    // Claude (and the chat history) get the expanded version. Unknown
    // /something stays as-is — better to send than to swallow.
    if (text.startsWith('/')) {
        const parsed = parseSlashInput(text);
        if (parsed !== null) {
            const expanded = expandSlashCommand(
                parsed,
                { currentPage: props.preview?.page?.name ?? '' },
                tInConversationLocale,
            );
            if (expanded !== null) {
                text = expanded;
            } else {
                // Recognised command but args are missing — leave a hint
                // and don't fire the turn. The menu shows usage already.
                errorText.value = tInConversationLocale(
                    `apps.builder.slash.${parsed.command.id}.usage`,
                )
                    ? `${tInConversationLocale('apps.builder.slash.usage_hint')}: /${parsed.command.name} ${tInConversationLocale(`apps.builder.slash.${parsed.command.id}.usage`)}`
                    : null;
                return;
            }
        }
    }
    // Allow sending an image with no caption — Claude can describe it on its
    // own. We just require *something* to send (text or attachment).
    if ((!text && !attachedFile.value) || sending.value) return;

    sending.value = true;
    errorText.value = null;
    liveActivity.value = null;
    liveSteps.value = [];

    // Capture the attachment locally so we can clear the staging area
    // immediately for snappy UX; if the request fails we restore it.
    const stagedFile = attachedFile.value;
    const stagedPreview = attachedPreviewUrl.value;
    attachedFile.value = null;
    attachedPreviewUrl.value = null;

    const messageText =
        text ||
        (stagedFile ? t('apps.builder.attachment_default_message') : '');
    input.value = '';

    try {
        // Endpoint enqueues a job and returns immediately with the user msg +
        // an assistant placeholder (status: streaming). Real content arrives
        // through BuilderStreamChunk/Complete events over Reverb.
        // Use multipart when there's an image so the controller's
        // `attachment` upload validation kicks in; plain JSON otherwise to
        // keep the common case lightweight.
        let response;
        if (stagedFile) {
            const form = new FormData();
            form.append('conversation_id', conversationId.value);
            form.append('message', messageText);
            form.append('attachment', stagedFile);
            if (selectedModel.value) form.append('model', selectedModel.value);
            response = await axios.post(
                `/apps/${props.app.id}/builder/messages`,
                form,
                {
                    headers: { 'Content-Type': 'multipart/form-data' },
                    timeout: 30_000,
                },
            );
        } else {
            response = await axios.post(
                `/apps/${props.app.id}/builder/messages`,
                {
                    conversation_id: conversationId.value,
                    message: messageText,
                    model: selectedModel.value || undefined,
                },
                { timeout: 10_000 },
            );
        }
        messages.value = response.data.messages;
        // Now safe to release the blob URL — the server returned its own URL
        // for the persisted image which the message list already references.
        if (stagedPreview) {
            URL.revokeObjectURL(stagedPreview);
        }
    } catch (e) {
        // Restore the staged attachment so the user can retry without
        // re-picking the file.
        if (stagedFile) {
            attachedFile.value = stagedFile;
            attachedPreviewUrl.value = stagedPreview;
        }
        const err = e as {
            code?: string;
            message?: string;
            response?: {
                status?: number;
                statusText?: string;
                headers?: Record<string, string>;
                data?: { message?: string; error?: string };
            };
        };
        if (err.code === 'ECONNABORTED') {
            errorText.value = "Couldn't reach the server to start the request.";
        } else if (err.response?.status === 429) {
            const retry = Number(err.response.headers?.['retry-after']);
            errorText.value = t('apps.builder.rate_limited', {
                seconds: Number.isFinite(retry) && retry > 0 ? retry : 60,
            });
        } else if (err.response) {
            const status = err.response.status ?? '???';
            const body =
                err.response.data?.message ??
                err.response.data?.error ??
                err.response.statusText ??
                '';
            errorText.value = `HTTP ${status}${body ? ' — ' + body : ''}`;
        } else {
            errorText.value =
                err.message ?? 'Network error — server unreachable.';
        }
        console.error('Builder sendMessage failed:', e);
    } finally {
        sending.value = false;
    }
}

/**
 * Capture the current preview pane with html2canvas, POST the PNG to the
 * visual-review endpoint, and let the existing Reverb stream handle the
 * assistant reply. Mirrors the send() flow but with a screenshot attached.
 */
async function requestVisualReview() {
    if (requestingReview.value || sending.value) return;
    const node = previewPane.value;
    if (!node) {
        errorText.value =
            'No hay preview que capturar — cambia al tab Preview primero.';
        return;
    }

    requestingReview.value = true;
    errorText.value = null;

    try {
        // Render the DOM to a canvas. backgroundColor:null preserves the
        // element's own background (light or dark) instead of forcing white.
        // scale:1 is enough for Claude vision — going higher just balloons the
        // upload size (we were hitting PHP's post_max_size at retina × 2).
        const rawCanvas = await html2canvas(node, {
            backgroundColor: null,
            useCORS: true,
            scale: 1,
            logging: false,
        });

        // Downscale to a sensible max so a tall scrolling page doesn't produce
        // a 4000×8000 monster. Claude sees screenshots up to ~1568 px
        // efficiently; we cap at 1600 to keep crisp text without overshoot.
        const MAX_DIM = 1600;
        const scaleDown = Math.min(
            1,
            MAX_DIM / Math.max(rawCanvas.width, rawCanvas.height),
        );
        let canvas = rawCanvas;
        if (scaleDown < 1) {
            const small = document.createElement('canvas');
            small.width = Math.round(rawCanvas.width * scaleDown);
            small.height = Math.round(rawCanvas.height * scaleDown);
            const ctx = small.getContext('2d');
            if (ctx) {
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(rawCanvas, 0, 0, small.width, small.height);
                canvas = small;
            }
        }

        // JPEG @ 0.85 cuts the upload to roughly a fifth of the equivalent PNG
        // without losing detail Claude needs for layout review.
        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.85),
        );
        if (!blob) {
            throw new Error('html2canvas devolvió un canvas vacío.');
        }

        const form = new FormData();
        form.append('conversation_id', conversationId.value);
        form.append('screenshot', blob, 'preview.jpg');
        if (props.preview?.page?.slug) {
            form.append('page_slug', props.preview.page.slug);
        }

        const { data } = await axios.post(
            `/apps/${props.app.id}/builder/visual-review`,
            form,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
                // Upload itself is small but PHP-side persistence can take a
                // beat — generous on the client side.
                timeout: 30_000,
            },
        );
        messages.value = data.messages;
    } catch (e) {
        const err = e as {
            message?: string;
            response?: {
                status?: number;
                headers?: Record<string, string>;
                data?: { message?: string };
            };
        };
        const status = err.response?.status;
        if (status === 429) {
            const retry = Number(err.response?.headers?.['retry-after']);
            errorText.value = t('apps.builder.rate_limited', {
                seconds: Number.isFinite(retry) && retry > 0 ? retry : 60,
            });
        } else {
            const body = err.response?.data?.message;
            errorText.value = status
                ? `HTTP ${status}${body ? ' — ' + body : ''}`
                : (err.message ?? 'No se pudo capturar el preview.');
        }
        console.error('Visual review failed:', e);
    } finally {
        requestingReview.value = false;
    }
}

// Echo subscription — receive streaming deltas, then the final message.
type ChannelHandle = ReturnType<typeof echo.private>;
let channel: ChannelHandle | null = null;

function subscribe() {
    unsubscribe();
    channel = echo.private(`builder.conversation.${conversationId.value}`);

    channel.listen(
        '.BuilderActivity',
        (data: {
            message_id: string;
            phase: string;
            model: string | null;
            tool: string | null;
        }) => {
            liveActivity.value = {
                phase: data.phase,
                model: data.model,
                tool: data.tool,
            };
            if (data.phase === 'tool' && data.tool) {
                // Accumulate the turn's tool steps (collapsing consecutive repeats)
                // so the chat shows a legible trail of what the model did.
                const last = liveSteps.value[liveSteps.value.length - 1];
                if (!last || last.tool !== data.tool) {
                    liveSteps.value.push({
                        tool: data.tool,
                        label: toolLabel(data.tool),
                    });
                }
            }
        },
    );

    channel.listen(
        '.BuilderStreamChunk',
        (data: { message_id: string; delta: string }) => {
            messages.value = messages.value.map((m) =>
                m.id === data.message_id
                    ? { ...m, content: (m.content ?? '') + data.delta }
                    : m,
            );
        },
    );

    channel.listen(
        '.BuilderStreamComplete',
        (payload: { message: Message }) => {
            liveActivity.value = null;
            liveSteps.value = [];
            messages.value = messages.value.map((m) =>
                m.id === payload.message.id ? payload.message : m,
            );
            // Server already created a new AppVersion if the user pre-approved
            // — partial reload to refresh the preview.
            router.reload({
                only: ['preview', 'manifest'],
                preserveScroll: true,
            });
        },
    );

    channel.listen(
        '.BuilderStreamError',
        (data: { message_id: string; error: string }) => {
            liveActivity.value = null;
            liveSteps.value = [];
            messages.value = messages.value.map((m) =>
                m.id === data.message_id
                    ? {
                          ...m,
                          content:
                              'Sorry — the AI request failed: ' + data.error,
                          status: 'none',
                      }
                    : m,
            );
            errorText.value = data.error;
        },
    );
}

function startNewConversation() {
    if (
        messages.value.length > 0 &&
        !window.confirm(t('apps.builder.new_conversation_confirm'))
    ) {
        return;
    }
    router.post(
        `/apps/${props.app.id}/builder/conversations`,
        {},
        { preserveScroll: false, preserveState: false },
    );
}

function unsubscribe() {
    if (channel) {
        channel.stopListening('.BuilderStreamChunk');
        channel.stopListening('.BuilderStreamComplete');
        channel.stopListening('.BuilderStreamError');
        echo.leave(`builder.conversation.${conversationId.value}`);
        channel = null;
    }
}

onMounted(() => {
    subscribe();
    nextTick(() => scrollToBottom('instant'));
    window.addEventListener('keydown', onWindowKeydown);
    document.addEventListener('fullscreenchange', onNativeFullscreenChange);

    // Track the lg breakpoint (Tailwind's 1024px) to decide whether the
    // side-by-side resizer applies.
    mediaQuery = window.matchMedia('(min-width: 1024px)');
    isLargeScreen.value = mediaQuery.matches;
    mediaQuery.addEventListener('change', handleMediaChange);

    // Restore a previously dragged width, clamped to the current viewport.
    try {
        const stored = localStorage.getItem(CHAT_WIDTH_STORAGE_KEY);
        if (stored !== null) {
            const parsed = Number(stored);
            if (Number.isFinite(parsed)) {
                nextTick(() => {
                    chatWidth.value = clampChatWidth(parsed);
                });
            }
        }
    } catch {
        // Ignore — fall back to the default width.
    }
});
onUnmounted(() => {
    unsubscribe();
    window.removeEventListener('keydown', onWindowKeydown);
    document.removeEventListener('fullscreenchange', onNativeFullscreenChange);
    if (mediaQuery) {
        mediaQuery.removeEventListener('change', handleMediaChange);
        mediaQuery = null;
    }
    document.body.style.userSelect = '';
    document.body.style.cursor = '';
    // Drop any blob URL we may have created for a staged but never-sent
    // attachment so we don't leak memory across page navigations.
    if (attachedPreviewUrl.value) {
        URL.revokeObjectURL(attachedPreviewUrl.value);
    }
});

function revertMessage(message: Message) {
    if (!window.confirm(t('apps.builder.revert_confirm'))) return;
    optimisticUpdate(message.id, { status: 'reverted' });
    router.post(
        `/apps/${props.app.id}/builder/messages/${message.id}/revert`,
        {},
        { preserveScroll: true, preserveState: false },
    );
}

// ---------- Plan proposal card (FR-1) ----------
// Approve sends a build instruction; the next turn edits the manifest. Change
// pre-fills the composer with the assumption so the user corrects it in one
// touch. Discard tells the builder to drop the plan.
function buildFromPlan() {
    input.value = tInConversationLocale('apps.builder.plan.approve_message');
    send();
}

function discardPlan() {
    input.value = tInConversationLocale('apps.builder.plan.discard_message');
    send();
}

function changePlanAssumption(assumption: {
    label?: string;
    default?: string;
}) {
    const label = assumption.label ?? '';
    input.value =
        tInConversationLocale('apps.builder.plan.change_prefix').replace(
            ':label',
            label,
        ) + ' ';
    // Surface the composer so the user can complete the correction.
    nextTick(() =>
        transcript.value?.scrollTo({ top: transcript.value.scrollHeight }),
    );
}

// Which message's change-summary tooltip is open. The summary is truncated in
// the row; clicking it reveals the full text in a tooltip. Only one open at a
// time — null means none.
const openSummaryId = ref<string | null>(null);

// Per-message accordion state — closed by default; the JSON patch is detail
// the user usually doesn't need to see.
const openPatches = ref<Record<string, boolean>>({});
function togglePatch(id: string) {
    openPatches.value = { ...openPatches.value, [id]: !openPatches.value[id] };
}

/**
 * Render the assistant's content as sanitised HTML. Claude routinely replies
 * with markdown (bullet lists, **bold**, `code`, headings) and rendering it
 * raw looks noisy. We sanitise with DOMPurify because marked produces HTML
 * the user could in theory inject through a long form value the assistant
 * echoed back — cheap defence against an escape that never happens.
 */
function renderAssistantContent(content: string | null): string {
    if (!content) return '';
    // marked.parse can return Promise<string> in async mode; we force sync.
    const raw = marked.parse(content, {
        async: false,
        breaks: true,
        gfm: true,
    }) as string;
    return DOMPurify.sanitize(raw);
}

function optimisticUpdate(id: string, patch: Partial<Message>) {
    messages.value = messages.value.map((m) =>
        m.id === id ? { ...m, ...patch } : m,
    );
}

function switchPage(slug: string) {
    router.visit(`/apps/${props.app.id}/builder?page=${slug}`, {
        preserveScroll: true,
    });
}

/**
 * Download the currently-previewed page as a standalone .html file. We take the
 * actual rendered DOM of the preview and inline every same-origin stylesheet so
 * the file looks identical when opened on its own (Tailwind utilities + design
 * tokens travel with it). Images stay as their (external) URLs.
 */
function downloadPreviewHtml() {
    const content = previewPane.value?.querySelector('[data-preview-content]');
    if (!content || !props.preview) return;

    let css = '';
    for (const sheet of Array.from(document.styleSheets)) {
        try {
            for (const rule of Array.from(sheet.cssRules))
                css += rule.cssText + '\n';
        } catch {
            // Cross-origin stylesheet — its rules aren't readable; skip it.
        }
    }

    const isDark = previewTheme.value === 'dark';
    const bg = isDark ? '#020617' : '#ffffff';
    const escape = (s: string) =>
        s.replace(
            /[&<>]/g,
            (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c] ?? c,
        );
    const title = escape(
        `${props.app.name} — ${props.preview.page.name ?? ''}`.trim(),
    );

    const html = `<!doctype html>
<html lang="${escape(previewLocale.value)}"${isDark ? ' class="dark"' : ''}>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<style>${css}</style>
</head>
<body style="margin:0;padding:1.25rem;background:${bg};">
${content.outerHTML}
</body>
</html>`;

    const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `${props.app.slug || 'app'}-${props.preview.page.slug || 'page'}.html`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

function prettyPatch(patch: Array<Record<string, unknown>> | null): string {
    if (!patch) return '';
    return JSON.stringify(patch, null, 2);
}

function statusLabel(status: Message['status']): string {
    switch (status) {
        case 'pending':
            return t('apps.builder.status_pending');
        case 'applied':
            return t('apps.builder.status_applied');
        case 'rejected':
            return t('apps.builder.status_rejected');
        case 'reverted':
            return t('apps.builder.status_reverted');
        default:
            return '';
    }
}

function statusTone(status: Message['status']): string {
    switch (status) {
        case 'pending':
            return 'border-amber-400/40 text-amber-300 bg-amber-400/10';
        case 'applied':
            return 'border-emerald-400/40 text-emerald-300 bg-emerald-400/10';
        case 'rejected':
            return 'border-red-400/40 text-red-300 bg-red-400/10';
        case 'reverted':
            return 'border-medium text-ink-muted bg-surface';
        default:
            return 'border-medium text-ink-muted bg-surface';
    }
}
</script>

<template>
    <Head :title="`${t('apps.builder.title')} · ${app.name}`" />

    <AppLayoutV2 :title="`${t('apps.builder.title')} · ${app.name}`" full-bleed>
        <div class="flex min-h-0 flex-1 flex-col gap-4 px-7 py-5">
            <header class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        :href="AppController.show(app.id).url"
                        class="inline-flex size-7 items-center justify-center rounded-xs border border-medium bg-surface text-ink-muted transition-colors hover:border-strong hover:text-ink"
                    >
                        <ArrowLeft class="size-3.5" />
                    </Link>
                    <div>
                        <h1
                            class="text-[18px] leading-tight font-semibold text-ink"
                        >
                            <Sparkles
                                class="-mt-0.5 mr-1 inline size-4 text-accent-blue"
                            />
                            {{ t('apps.builder.title') }}
                        </h1>
                        <p class="text-xs text-ink-muted">{{ app.name }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <!-- Accent colour: brand colour for buttons / links / highlights. -->
                    <div
                        v-if="viewMode === 'preview'"
                        class="inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-2 py-1"
                        title="Accent colour — drives buttons, links and highlights"
                    >
                        <span class="mr-0.5 text-[11px] text-ink-muted"
                            >Accent</span
                        >
                        <button
                            v-for="c in ACCENT_PRESETS"
                            :key="c"
                            type="button"
                            class="size-4 rounded-full border border-soft transition-transform hover:scale-110"
                            :class="
                                effectiveAccent?.toLowerCase() === c
                                    ? 'ring-2 ring-ink ring-offset-1 ring-offset-navy'
                                    : ''
                            "
                            :style="{ background: c }"
                            :title="c"
                            @click="setAccent(c)"
                        />
                        <label
                            class="ml-0.5 size-5 cursor-pointer rounded-full border border-soft"
                            :style="{
                                background: effectiveAccent ?? '#0096ff',
                            }"
                            :title="'Custom: ' + (effectiveAccent ?? '#0096ff')"
                        >
                            <input
                                type="color"
                                class="size-0 opacity-0"
                                :value="effectiveAccent ?? '#0096ff'"
                                @input="
                                    setAccent(
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    )
                                "
                            />
                        </label>
                    </div>

                    <!-- Adopt the organization Brandbook in one click. -->
                    <button
                        v-if="viewMode === 'preview' && hasOrgBrand"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink-muted transition-colors hover:border-accent-blue/40 hover:bg-accent-blue/10 hover:text-accent-blue"
                        :title="t('apps.builder.use_brand_hint')"
                        @click="useOrgBrand"
                    >
                        <Sparkles class="size-3.5" />
                        {{ t('apps.builder.use_brand') }}
                    </button>

                    <!-- Layers: every part of the app, one click away for consultation. -->
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                        @click="layersOpen = true"
                    >
                        <Layers class="size-3.5" />
                        Layers
                    </button>

                    <div
                        class="inline-flex items-center rounded-pill border border-medium bg-surface p-0.5"
                    >
                        <button
                            v-for="m in [
                                {
                                    id: 'preview',
                                    label: t('apps.builder.tab_preview'),
                                    icon: Eye,
                                },
                                {
                                    id: 'schema',
                                    label: t('apps.builder.tab_schema'),
                                    icon: Database,
                                },
                                {
                                    id: 'workflows',
                                    label: t('apps.builder.tab_workflows'),
                                    icon: WorkflowIcon,
                                },
                                {
                                    id: 'access',
                                    label: t('apps.builder.tab_access'),
                                    icon: ShieldCheck,
                                },
                                {
                                    id: 'manifest',
                                    label: t('apps.builder.tab_manifest'),
                                    icon: Code,
                                },
                            ] as const"
                            :key="m.id"
                            type="button"
                            @click="viewMode = m.id"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-pill px-3 py-1 text-xs transition-colors',
                                viewMode === m.id
                                    ? 'bg-accent-blue/15 text-accent-blue'
                                    : 'text-ink-muted hover:text-ink',
                            ]"
                        >
                            <component :is="m.icon" class="size-3.5" />
                            {{ m.label }}
                        </button>
                    </div>
                </div>
            </header>

            <div ref="gridEl" :class="gridClass" :style="gridStyle">
                <section
                    ref="chatSection"
                    v-show="fullscreenPanel !== 'work'"
                    :class="[
                        'relative flex min-h-0 flex-col rounded-sp-sm border border-soft bg-navy',
                        ...fullscreenClassFor('chat'),
                    ]"
                    @dragover="onDragOver"
                    @dragleave="onDragLeave"
                    @drop="onDrop"
                >
                    <!-- Drag-and-drop overlay. Only shows while the user is dragging
                         a file over the chat pane — it covers the whole pane so any
                         drop lands on the handler, including over the transcript. -->
                    <div
                        v-if="isDraggingOver"
                        class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-sp-sm border-2 border-dashed border-accent-blue/60 bg-accent-blue/10"
                    >
                        <p
                            class="rounded-pill bg-navy/90 px-4 py-2 text-sm font-medium text-accent-blue"
                        >
                            <Paperclip class="mr-1 inline size-4" />
                            {{ t('apps.builder.drop_to_attach') }}
                        </p>
                    </div>

                    <header
                        class="flex items-center justify-between gap-2 border-b border-soft px-4 py-3"
                    >
                        <h2
                            class="text-xs font-medium tracking-wide text-ink-muted uppercase"
                        >
                            {{ t('apps.builder.chat_heading') }}
                        </h2>
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                @click="startNewConversation"
                                :disabled="aiIsThinking"
                                :title="t('apps.builder.new_conversation')"
                                class="inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-2 py-0.5 text-[10px] tracking-wider text-ink-muted uppercase transition-colors hover:border-strong hover:text-ink disabled:opacity-50"
                            >
                                <RotateCcw class="size-3" />
                                {{ t('apps.builder.new_conversation') }}
                            </button>
                            <button
                                type="button"
                                @click="toggleFullscreen('chat')"
                                :title="
                                    fullscreenPanel === 'chat'
                                        ? t('apps.builder.exit_fullscreen')
                                        : t('apps.builder.enter_fullscreen')
                                "
                                class="inline-flex size-6 items-center justify-center rounded-pill text-ink-muted transition-colors hover:bg-surface-hover hover:text-ink"
                            >
                                <Minimize2
                                    v-if="fullscreenPanel === 'chat'"
                                    class="size-3"
                                />
                                <Maximize2 v-else class="size-3" />
                            </button>
                        </div>
                    </header>

                    <div
                        ref="transcript"
                        class="flex-1 space-y-4 overflow-y-auto px-4 py-4"
                    >
                        <div
                            v-if="messages.length === 0"
                            class="rounded-sp-sm border border-dashed border-soft bg-surface p-5"
                        >
                            <div
                                class="flex items-center gap-2 text-sm font-medium text-ink"
                            >
                                <Sparkles class="size-4 text-accent-blue" />
                                {{ t('apps.builder.title') }}
                            </div>
                            <p class="mt-2 text-xs text-ink-muted">
                                {{ t('apps.builder.empty_prompt') }}
                            </p>
                            <!-- Quick-start prompts: never a blank box — show what
                                 you can ask, one click pre-fills the composer. -->
                            <div
                                v-if="chipSuggestions.length"
                                class="mt-3 flex flex-wrap gap-2"
                            >
                                <button
                                    v-for="s in chipSuggestions.slice(0, 5)"
                                    :key="s.id"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-navy px-3 py-1.5 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                                    @click="applyChip(s)"
                                >
                                    <component :is="s.icon" class="size-3.5" />
                                    {{ s.label }}
                                </button>
                            </div>
                        </div>

                        <article
                            v-for="m in messages"
                            :key="m.id"
                            class="space-y-2"
                        >
                            <div
                                v-if="m.role === 'user'"
                                class="ml-8 space-y-2 rounded-sp-sm bg-accent-blue/10 px-3 py-2 text-sm whitespace-pre-wrap text-ink"
                            >
                                <!-- Inline image preview when the user attached one
                                     with this turn. Capped at ~10rem so a tall portrait
                                     screenshot doesn't dominate the transcript. -->
                                <a
                                    v-if="m.attachment_url"
                                    :href="m.attachment_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="block"
                                >
                                    <img
                                        :src="m.attachment_url"
                                        :alt="t('apps.builder.attach_image')"
                                        class="max-h-40 w-auto rounded-sp-sm border border-soft object-contain"
                                        loading="lazy"
                                    />
                                </a>
                                <div v-if="m.content">{{ m.content }}</div>
                                <span v-else-if="!m.attachment_url">…</span>
                            </div>
                            <div
                                v-else
                                class="builder-md mr-8 rounded-sp-sm border border-soft bg-surface px-3 py-2 text-sm text-ink"
                            >
                                <!-- Live activity trail: what the model did this
                                     turn (each tool it called) + what it's doing
                                     now, so the wait is never an opaque pause. -->
                                <div
                                    v-if="
                                        m.status === 'streaming' &&
                                        (liveSteps.length > 0 || liveActivity)
                                    "
                                    class="mb-2 space-y-1 border-l-2 border-accent-blue/40 pl-2.5"
                                >
                                    <div
                                        v-for="(step, i) in liveSteps"
                                        :key="i"
                                        class="flex items-center gap-1.5 text-xs text-ink-muted"
                                    >
                                        <Check class="size-3 text-sp-success" />
                                        <span>{{ step.label }}</span>
                                    </div>
                                    <div
                                        v-if="liveActivity"
                                        class="flex items-center gap-1.5 text-xs text-ink"
                                    >
                                        <Loader2
                                            class="size-3 animate-spin text-accent-blue"
                                        />
                                        <span>{{ activityStatus }}</span>
                                    </div>
                                </div>
                                <div
                                    v-if="m.content"
                                    v-html="renderAssistantContent(m.content)"
                                />
                                <span
                                    v-if="m.status === 'streaming'"
                                    class="ml-0.5 inline-block h-3 w-1 animate-pulse bg-accent-blue align-middle"
                                />
                            </div>

                            <div
                                v-if="m.proposed_patch"
                                class="mr-8 overflow-hidden rounded-sp-sm border border-soft bg-navy/50"
                            >
                                <div
                                    class="flex items-center justify-between gap-3 px-3 py-2"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <span
                                            :class="[
                                                'inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] tracking-wider uppercase',
                                                statusTone(m.status),
                                            ]"
                                        >
                                            <Check
                                                v-if="m.status === 'applied'"
                                                class="mr-0.5 size-3"
                                            />
                                            <RotateCcw
                                                v-else-if="
                                                    m.status === 'reverted'
                                                "
                                                class="mr-0.5 size-3"
                                            />
                                            {{ statusLabel(m.status) }}
                                        </span>
                                        <TooltipProvider
                                            v-if="
                                                m.status === 'applied' ||
                                                m.status === 'reverted'
                                            "
                                            :delay-duration="0"
                                            disable-closing-trigger
                                        >
                                            <!-- Controlled open: only react to close
                                                 requests (Esc / outside click) so the
                                                 tooltip opens on click, not hover. -->
                                            <Tooltip
                                                :open="openSummaryId === m.id"
                                                @update:open="
                                                    (v: boolean) => {
                                                        if (!v)
                                                            openSummaryId =
                                                                null;
                                                    }
                                                "
                                            >
                                                <TooltipTrigger as-child>
                                                    <button
                                                        type="button"
                                                        class="min-w-0 truncate text-left text-[11px] text-ink-muted transition-colors hover:text-ink"
                                                        :title="
                                                            t(
                                                                'apps.builder.show_full_summary',
                                                            )
                                                        "
                                                        @click="
                                                            openSummaryId =
                                                                openSummaryId ===
                                                                m.id
                                                                    ? null
                                                                    : m.id
                                                        "
                                                    >
                                                        {{
                                                            m.change_summary ||
                                                            t(
                                                                'apps.builder.change',
                                                            )
                                                        }}
                                                    </button>
                                                </TooltipTrigger>
                                                <TooltipContent
                                                    class="max-w-xs text-left break-words whitespace-normal"
                                                >
                                                    {{
                                                        m.change_summary ||
                                                        t('apps.builder.change')
                                                    }}
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-1.5"
                                    >
                                        <button
                                            v-if="m.status === 'applied'"
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-pill border border-medium bg-surface px-2.5 py-1 text-[11px] text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                                            @click="revertMessage(m)"
                                        >
                                            <RotateCcw class="size-3" />
                                            {{ t('apps.builder.revert') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-xs px-2 py-1 text-[10px] tracking-wider text-ink-muted uppercase transition-colors hover:bg-surface hover:text-ink"
                                            @click="togglePatch(m.id)"
                                            :aria-expanded="!!openPatches[m.id]"
                                        >
                                            <ChevronDown
                                                class="size-3 transition-transform"
                                                :class="
                                                    openPatches[m.id]
                                                        ? 'rotate-180'
                                                        : ''
                                                "
                                            />
                                            {{
                                                openPatches[m.id]
                                                    ? t(
                                                          'apps.builder.hide_patch',
                                                      )
                                                    : t(
                                                          'apps.builder.show_patch',
                                                      )
                                            }}
                                        </button>
                                    </div>
                                </div>
                                <pre
                                    v-if="openPatches[m.id]"
                                    class="max-h-72 overflow-auto border-t border-soft bg-black/30 p-3 font-mono text-[11px] leading-tight text-ink"
                                    >{{ prettyPatch(m.proposed_patch) }}</pre
                                >
                            </div>

                            <BuilderPlanCard
                                v-if="m.plan"
                                :plan="m.plan"
                                @build="buildFromPlan"
                                @discard="discardPlan"
                                @change="changePlanAssumption"
                            />

                            <BuilderIntegrationCard
                                v-if="m.integration_proposal"
                                :proposal="m.integration_proposal"
                                :app-id="app.id"
                            />
                        </article>
                    </div>

                    <footer class="border-t border-soft px-4 py-3">
                        <!-- Staged attachment thumbnail — sits above the input so
                             the user sees exactly what'll be sent with the next
                             turn and can drop it before hitting Send. -->
                        <div
                            v-if="attachedPreviewUrl"
                            class="mb-2 flex items-center gap-2 rounded-sp-sm border border-soft bg-surface p-2"
                        >
                            <img
                                :src="attachedPreviewUrl"
                                :alt="t('apps.builder.attach_image')"
                                class="size-12 rounded object-cover"
                            />
                            <span
                                class="flex-1 truncate text-[11px] text-ink-muted"
                            >
                                {{ attachedFile?.name }}
                            </span>
                            <button
                                type="button"
                                @click="clearAttachment"
                                :title="t('apps.builder.remove_attachment')"
                                class="rounded-full p-1 text-ink-muted transition-colors hover:bg-surface-hover hover:text-ink"
                            >
                                <X class="size-3.5" />
                            </button>
                        </div>

                        <!-- Always-on status bar: which model is in play and what
                             it's doing. Idle shows the model + "Ready"; mid-turn it
                             pulses with the live phase. -->
                        <div
                            class="mb-2 flex items-center gap-2 rounded-pill border border-soft bg-navy/60 px-3 py-1.5 text-xs"
                        >
                            <Loader2
                                v-if="isBusy"
                                class="size-3.5 shrink-0 animate-spin text-accent-blue"
                            />
                            <Sparkles
                                v-else
                                class="size-3.5 shrink-0 text-accent-blue"
                            />
                            <span class="font-medium text-ink">{{
                                activityModelLabel
                            }}</span>
                            <span class="text-ink-subtle">·</span>
                            <span class="truncate text-ink-muted">{{
                                activityStatus
                            }}</span>
                        </div>

                        <form class="relative" @submit.prevent="send">
                            <!-- Slash command menu — floats above the composer.
                                 Kept inside the form so it positions against it. -->
                            <SlashCommandMenu
                                :open="slashMenuOpen"
                                :commands="slashMatches"
                                :highlighted-index="slashHighlightedIndex"
                                @select="selectSlashCommand"
                                @hover="slashHighlightedIndex = $event"
                            />

                            <!-- Hidden native file input wired to the paperclip button. -->
                            <input
                                ref="fileInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/gif"
                                class="hidden"
                                @change="onAttachmentChange"
                            />

                            <!-- Full-width multi-line composer. Enter sends,
                                 Shift+Enter inserts a newline (see onComposerKeydown). -->
                            <textarea
                                ref="inputEl"
                                v-model="input"
                                rows="3"
                                class="max-h-56 min-h-[76px] w-full resize-none rounded-md border border-medium bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-subtle"
                                :placeholder="
                                    aiIsThinking
                                        ? t('apps.builder.input_thinking')
                                        : t('apps.builder.input_placeholder')
                                "
                                :disabled="sending || aiIsThinking"
                                @paste="onPaste"
                                @keydown="onComposerKeydown"
                            />

                            <!-- Controls row under the textarea: tools on the
                                 left, send on the right. -->
                            <div
                                class="mt-2 flex items-center justify-between gap-2"
                            >
                                <div class="flex items-center gap-1.5">
                                    <!-- Contextual suggestions, tucked behind an
                                         icon so they don't crowd the composer.
                                         Each item pre-fills the textarea. -->
                                    <DropdownMenu v-if="hasSuggestions">
                                        <DropdownMenuTrigger as-child>
                                            <button
                                                type="button"
                                                :title="
                                                    t(
                                                        'apps.builder.suggestions_heading',
                                                    )
                                                "
                                                :aria-label="
                                                    t(
                                                        'apps.builder.suggestions_heading',
                                                    )
                                                "
                                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-medium bg-surface text-ink-muted transition-colors hover:border-accent-blue/40 hover:bg-accent-blue/10 hover:text-accent-blue disabled:opacity-50 data-[state=open]:border-accent-blue/40 data-[state=open]:text-accent-blue"
                                            >
                                                <Lightbulb class="size-4" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="start"
                                            side="top"
                                            :side-offset="6"
                                            class="w-64"
                                        >
                                            <DropdownMenuLabel
                                                class="text-[10px] tracking-wider text-ink-muted uppercase"
                                            >
                                                {{
                                                    t(
                                                        'apps.builder.suggestions_heading',
                                                    )
                                                }}
                                            </DropdownMenuLabel>
                                            <DropdownMenuItem
                                                v-for="suggestion in chipSuggestions"
                                                :key="suggestion.id"
                                                class="gap-2"
                                                @select="applyChip(suggestion)"
                                            >
                                                <component
                                                    :is="suggestion.icon"
                                                    class="size-4 text-ink-muted"
                                                />
                                                {{ suggestion.label }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>

                                    <!-- AI model picker, tucked behind a gear. -->
                                    <DropdownMenu
                                        v-if="
                                            props.models &&
                                            props.models.length > 1
                                        "
                                    >
                                        <DropdownMenuTrigger as-child>
                                            <button
                                                type="button"
                                                :disabled="
                                                    sending || aiIsThinking
                                                "
                                                :title="`${t('apps.builder.model_picker_label')}: ${selectedModelLabel}`"
                                                :aria-label="
                                                    t(
                                                        'apps.builder.model_picker_label',
                                                    )
                                                "
                                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-medium bg-surface text-ink-muted transition-colors hover:border-strong hover:text-ink disabled:opacity-50 data-[state=open]:border-accent-blue/40 data-[state=open]:text-accent-blue"
                                            >
                                                <Settings2 class="size-4" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="start"
                                            side="top"
                                            :side-offset="6"
                                            class="w-60"
                                        >
                                            <DropdownMenuLabel
                                                class="text-[10px] tracking-wider text-ink-muted uppercase"
                                            >
                                                {{
                                                    t(
                                                        'apps.builder.model_picker_label',
                                                    )
                                                }}
                                            </DropdownMenuLabel>
                                            <DropdownMenuRadioGroup
                                                v-model="selectedModel"
                                            >
                                                <DropdownMenuRadioItem
                                                    v-for="m in props.models"
                                                    :key="m.id"
                                                    :value="m.id"
                                                >
                                                    {{ m.label }}
                                                </DropdownMenuRadioItem>
                                            </DropdownMenuRadioGroup>
                                        </DropdownMenuContent>
                                    </DropdownMenu>

                                    <button
                                        type="button"
                                        @click="pickAttachment"
                                        :disabled="sending || aiIsThinking"
                                        :title="t('apps.builder.attach_image')"
                                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-medium bg-surface text-ink-muted transition-colors hover:border-strong hover:text-ink disabled:opacity-50"
                                    >
                                        <Paperclip class="size-4" />
                                    </button>
                                </div>

                                <button
                                    v-if="!aiIsThinking"
                                    type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                                    :disabled="
                                        sending ||
                                        (!input.trim() && !attachedFile)
                                    "
                                >
                                    <Send class="size-3.5" />
                                    {{ t('apps.builder.send') }}
                                </button>
                                <div
                                    v-else
                                    class="inline-flex items-center gap-1.5 rounded-pill border border-accent-blue/40 bg-accent-blue/10 px-3.5 py-1.5 text-xs font-medium text-accent-blue"
                                    aria-live="polite"
                                >
                                    <Loader2 class="size-3.5 animate-spin" />
                                    {{ t('apps.builder.thinking') }}
                                </div>
                            </div>
                        </form>
                        <p
                            v-if="errorText"
                            class="mt-2 text-[11px] text-red-400"
                        >
                            {{ errorText }}
                        </p>
                    </footer>
                </section>

                <!-- Draggable splitter between the two panes. Only present on
                     large screens with both panes visible; drag to retune the
                     split, double-click to reset, arrow keys for fine control. -->
                <div
                    v-if="resizable"
                    role="separator"
                    aria-orientation="vertical"
                    tabindex="0"
                    :aria-label="t('apps.builder.resize_panels')"
                    :aria-valuenow="Math.round(chatWidth)"
                    :aria-valuemin="MIN_CHAT_WIDTH"
                    class="group relative flex cursor-col-resize touch-none items-center justify-center outline-none"
                    @pointerdown="startResize"
                    @dblclick="resetResize"
                    @keydown="onResizerKeydown"
                >
                    <!-- Just the grab icon — no background or border. -->
                    <GripVertical
                        class="size-4 transition-colors"
                        :class="
                            isResizing
                                ? 'text-accent-blue'
                                : 'text-ink-muted group-hover:text-accent-blue group-focus-visible:text-accent-blue'
                        "
                    />
                </div>

                <section
                    ref="workSection"
                    v-show="fullscreenPanel !== 'chat'"
                    :class="[
                        'flex min-h-0 flex-col rounded-sp-sm border border-soft bg-navy',
                        ...fullscreenClassFor('work'),
                    ]"
                >
                    <header
                        class="flex items-center justify-between gap-3 border-b border-soft px-4 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <component
                                :is="currentViewMeta.icon"
                                class="size-3.5 text-ink-muted"
                            />
                            <h2
                                class="text-xs font-medium tracking-wide text-ink-muted uppercase"
                            >
                                {{ currentViewMeta.heading }}
                            </h2>
                        </div>
                        <div class="flex items-center gap-2">
                            <template v-if="viewMode === 'preview'">
                                <nav
                                    v-if="preview && preview.pages.length > 1"
                                    class="flex flex-wrap gap-1"
                                >
                                    <button
                                        v-for="p in preview.pages"
                                        :key="p.id"
                                        type="button"
                                        @click="switchPage(p.slug)"
                                        :class="[
                                            'inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[11px] transition-colors',
                                            preview.page.slug === p.slug
                                                ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                                                : 'border-medium bg-surface text-ink-muted hover:border-strong hover:text-ink',
                                        ]"
                                    >
                                        {{ p.name }}
                                    </button>
                                </nav>
                            </template>

                            <button
                                type="button"
                                @click="toggleFullscreen('work')"
                                :title="
                                    fullscreenPanel === 'work'
                                        ? t('apps.builder.exit_fullscreen')
                                        : t('apps.builder.enter_fullscreen')
                                "
                                class="inline-flex size-6 items-center justify-center rounded-pill text-ink-muted transition-colors hover:bg-surface-hover hover:text-ink"
                            >
                                <Minimize2
                                    v-if="fullscreenPanel === 'work'"
                                    class="size-3"
                                />
                                <Maximize2 v-else class="size-3" />
                            </button>

                            <!-- Panel options, tucked behind a three-dots menu.
                                 Preview-only actions (import wireframe, visual
                                 review) live here so the header stays clean. -->
                            <DropdownMenu v-if="viewMode === 'preview'">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        :title="t('apps.builder.panel_options')"
                                        :aria-label="
                                            t('apps.builder.panel_options')
                                        "
                                        class="inline-flex size-6 items-center justify-center rounded-pill text-ink-muted transition-colors hover:bg-surface-hover hover:text-ink data-[state=open]:bg-surface-hover data-[state=open]:text-ink"
                                    >
                                        <MoreVertical class="size-3.5" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    :side-offset="6"
                                    class="w-56"
                                >
                                    <DropdownMenuItem
                                        :disabled="sending || aiIsThinking"
                                        class="gap-2"
                                        @select="wireframeOpen = true"
                                    >
                                        <ImagePlus
                                            class="size-4 text-ink-muted"
                                        />
                                        {{ t('apps.builder.wireframe.button') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        :disabled="
                                            requestingReview ||
                                            sending ||
                                            !preview
                                        "
                                        class="gap-2"
                                        @select="requestVisualReview"
                                    >
                                        <Loader2
                                            v-if="requestingReview"
                                            class="size-4 animate-spin"
                                        />
                                        <Camera
                                            v-else
                                            class="size-4 text-ink-muted"
                                        />
                                        {{
                                            requestingReview
                                                ? t(
                                                      'apps.builder.visual_review_running',
                                                  )
                                                : t(
                                                      'apps.builder.visual_review',
                                                  )
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        :disabled="!preview"
                                        class="gap-2"
                                        @select="downloadPreviewHtml"
                                    >
                                        <Download
                                            class="size-4 text-ink-muted"
                                        />
                                        {{ t('apps.builder.download_html') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </header>

                    <div
                        v-if="viewMode === 'preview'"
                        ref="previewPane"
                        :class="[
                            'flex-1 overflow-auto p-5 transition-colors',
                            // Force the previewed app's own theme here (independent of the
                            // builder chrome's mode) so the author can check light AND dark.
                            preview
                                ? previewTheme === 'dark'
                                    ? 'theme-dark'
                                    : 'theme-light'
                                : '',
                            preview ? 'bg-navy-deep' : '',
                        ]"
                    >
                        <div
                            v-if="preview"
                            data-preview-content
                            class="sp-app-surface"
                            :style="previewSurfaceStyle"
                        >
                            <!-- Author CSS, pre-scoped server-side to .sp-app-surface. -->
                            <component
                                :is="'style'"
                                v-if="preview.custom_css"
                                :text-content="preview.custom_css"
                            />

                            <!-- Sidebar layout mirrors the runtime. -->
                            <div
                                v-if="previewSidebar"
                                class="flex min-h-[400px]"
                            >
                                <SiteSidebar
                                    embedded
                                    :brand="previewBrand"
                                    :nav-items="previewNavItems"
                                    :pages="preview.pages"
                                    :current-slug="preview.page.slug"
                                />
                                <div class="min-w-0 flex-1">
                                    <header
                                        class="flex shrink-0 flex-col justify-center border-b px-6"
                                        :class="
                                            previewBreadcrumbBlock
                                                ? 'gap-1.5 py-3.5'
                                                : 'h-16'
                                        "
                                        :style="{
                                            borderColor:
                                                'color-mix(in srgb, currentColor 12%, transparent)',
                                        }"
                                    >
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                class="grid size-8 shrink-0 place-items-center rounded-md text-ink-muted transition-colors hover:bg-[color-mix(in_srgb,currentColor_8%,transparent)]"
                                                :title="
                                                    previewSidebarCollapsed
                                                        ? 'Expandir menú'
                                                        : 'Colapsar menú'
                                                "
                                                @click="
                                                    previewSidebarCollapsed =
                                                        !previewSidebarCollapsed
                                                "
                                            >
                                                <PanelLeftOpen
                                                    v-if="
                                                        previewSidebarCollapsed
                                                    "
                                                    class="size-5"
                                                />
                                                <PanelLeftClose
                                                    v-else
                                                    class="size-5"
                                                />
                                            </button>
                                            <BlockBreadcrumb
                                                v-if="previewBreadcrumbBlock"
                                                :block="(previewBreadcrumbBlock as any)"
                                            />
                                            <h1
                                                v-else
                                                class="truncate text-xl font-semibold tracking-tight"
                                            >
                                                {{ preview.page.name }}
                                            </h1>
                                        </div>
                                        <h1
                                            v-if="previewBreadcrumbBlock"
                                            class="truncate text-2xl font-bold tracking-tight sm:text-3xl"
                                        >
                                            {{ preview.page.name }}
                                        </h1>
                                    </header>
                                    <div class="space-y-4 px-6 py-6">
                                        <AppRenderer
                                            :blocks="previewContentBlocks"
                                            :block-data="preview.block_data"
                                            :objects="preview.objects"
                                            :locale="previewLocale"
                                            :default-currency="previewCurrency"
                                            :theme="previewTheme"
                                        />
                                    </div>
                                    <SiteFooter
                                        :footer="previewFooter"
                                        :brand-name="previewBrand.name"
                                    />
                                </div>
                            </div>

                            <!-- Top-header layout (default). -->
                            <template v-else>
                                <SiteHeader
                                    :brand="previewBrand"
                                    :pages="preview.pages"
                                    :current-slug="preview.page.slug"
                                />
                                <div class="space-y-4 py-6">
                                    <AppRenderer
                                        :blocks="preview.page.blocks"
                                        :block-data="preview.block_data"
                                        :objects="preview.objects"
                                        :locale="previewLocale"
                                        :default-currency="previewCurrency"
                                        :theme="previewTheme"
                                    />
                                </div>
                                <SiteFooter
                                    :footer="previewFooter"
                                    :brand-name="previewBrand.name"
                                />
                            </template>
                        </div>
                        <div
                            v-else
                            class="flex h-full flex-col items-center justify-center gap-2 text-center"
                        >
                            <Eye class="size-8 text-ink-subtle" />
                            <p class="text-sm text-ink-muted">
                                {{ t('apps.builder.preview_empty') }}
                            </p>
                            <p class="max-w-xs text-xs text-ink-subtle">
                                {{ t('apps.builder.preview_empty_hint') }}
                            </p>
                        </div>
                    </div>

                    <SchemaView
                        v-else-if="viewMode === 'schema'"
                        :schema="schema"
                        :app-id="app.id"
                        class="min-h-0 flex-1"
                    />

                    <AppWorkflowsTab
                        v-else-if="viewMode === 'workflows'"
                        :app-id="app.id"
                        :workflows="
                            (manifest?.workflows as never[] | undefined) ?? []
                        "
                        :objects="
                            (manifest?.objects as never[] | undefined) ?? []
                        "
                        @manifest-updated="
                            () =>
                                router.reload({
                                    only: ['preview', 'manifest', 'schema'],
                                    preserveScroll: true,
                                })
                        "
                        class="min-h-0 flex-1"
                    />

                    <div
                        v-else-if="viewMode === 'access'"
                        class="min-h-0 flex-1 overflow-auto"
                    >
                        <AppAccessPanel :app-id="app.id" />
                    </div>

                    <pre
                        v-else
                        class="flex-1 overflow-auto bg-black/30 p-4 font-mono text-[11px] leading-snug text-ink"
                        >{{
                            manifest
                                ? JSON.stringify(manifest, null, 2)
                                : t('apps.show.no_manifest')
                        }}</pre
                    >
                </section>
            </div>
        </div>

        <!-- Wireframe import dialog. Mounted outside the grid so the Radix
             Dialog portals correctly and isn't clipped by the surrounding
             rounded panels. -->
        <WireframeImportDialog
            v-model:open="wireframeOpen"
            :app-id="app.id"
            :conversation-id="conversationId"
            @imported="onWireframeImported"
        />

        <!-- Layers explorer — every part of the app at hand for consultation. -->
        <Sheet v-model:open="layersOpen">
            <SheetContent side="left" class="w-[22rem] overflow-y-auto p-0">
                <SheetHeader class="border-b border-soft px-4 py-3">
                    <SheetTitle class="flex items-center gap-2 text-sm">
                        <Layers class="size-4 text-accent-blue" />
                        App layers
                    </SheetTitle>
                </SheetHeader>
                <LayersExplorer
                    :manifest="manifest"
                    :schema="schema"
                    :versions="versions"
                />
            </SheetContent>
        </Sheet>
    </AppLayoutV2>
</template>

<style scoped>
/*
 * Minimal markdown styling for assistant replies. Scoped to .builder-md so
 * we don't accidentally restyle the runtime preview that lives in the same
 * page. Mirrors BlockMarkdown.vue's deep selectors — kept inline here
 * rather than reusing the markdown block component because that one
 * includes its own theme tokens / surface that don't belong inside a chat
 * bubble.
 */
.builder-md :deep(p) {
    margin: 0.3rem 0;
    line-height: 1.55;
}
.builder-md :deep(p:first-child) {
    margin-top: 0;
}
.builder-md :deep(p:last-child) {
    margin-bottom: 0;
}
.builder-md :deep(h1) {
    font-size: 1.05rem;
    font-weight: 600;
    margin: 0.6rem 0 0.3rem;
}
.builder-md :deep(h2) {
    font-size: 1rem;
    font-weight: 600;
    margin: 0.55rem 0 0.25rem;
}
.builder-md :deep(h3) {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0.5rem 0 0.2rem;
}
.builder-md :deep(ul),
.builder-md :deep(ol) {
    padding-left: 1.1rem;
    margin: 0.3rem 0;
}
.builder-md :deep(ul) {
    list-style: disc;
}
.builder-md :deep(ol) {
    list-style: decimal;
}
.builder-md :deep(li) {
    margin: 0.1rem 0;
}
.builder-md :deep(li > p) {
    margin: 0;
}
.builder-md :deep(strong) {
    font-weight: 600;
}
.builder-md :deep(em) {
    font-style: italic;
}
.builder-md :deep(code) {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.82em;
    padding: 0.05rem 0.3rem;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 3px;
    word-break: break-word;
}
.builder-md :deep(pre) {
    background: rgba(0, 0, 0, 0.35);
    padding: 0.65rem 0.8rem;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 0.82em;
    margin: 0.4rem 0;
    line-height: 1.5;
}
.builder-md :deep(pre code) {
    background: transparent;
    padding: 0;
}
.builder-md :deep(blockquote) {
    border-left: 3px solid rgba(255, 255, 255, 0.2);
    padding-left: 0.65rem;
    opacity: 0.85;
    margin: 0.4rem 0;
}
.builder-md :deep(a) {
    color: rgb(96 165 250);
    text-decoration: underline;
    text-underline-offset: 2px;
}
.builder-md :deep(hr) {
    border: 0;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin: 0.6rem 0;
}
.builder-md :deep(table) {
    border-collapse: collapse;
    margin: 0.4rem 0;
    font-size: 0.85em;
}
.builder-md :deep(th),
.builder-md :deep(td) {
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 0.2rem 0.45rem;
    text-align: left;
}
.builder-md :deep(th) {
    font-weight: 600;
    background: rgba(255, 255, 255, 0.04);
}
</style>
