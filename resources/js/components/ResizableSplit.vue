<script setup lang="ts">
/**
 * Two-pane horizontal split with a draggable gutter — the App Builder layout
 * pattern (chat on the left, work area on the right). The left width (px)
 * persists in localStorage. On small screens the panes stack; while `showLeft`
 * is false the right pane fills the whole width and the gutter is hidden.
 */
import { GripVertical } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        storageKey: string;
        defaultLeftWidth?: number;
        minLeft?: number;
        minRight?: number;
        showLeft?: boolean;
        resizeLabel?: string;
    }>(),
    {
        defaultLeftWidth: 420,
        minLeft: 320,
        minRight: 420,
        showLeft: true,
        resizeLabel: 'Resize panels',
    },
);

const RESIZER_WIDTH = 12; // px — must match the middle grid track.

const gridEl = ref<HTMLElement | null>(null);
const leftWidth = ref(props.defaultLeftWidth);
const isLargeScreen = ref(true);
const isResizing = ref(false);

const resizable = computed(() => props.showLeft && isLargeScreen.value);

const gridClass = computed(() =>
    isLargeScreen.value
        ? 'grid min-h-0 flex-1 grid-cols-1'
        : 'grid min-h-0 flex-1 grid-cols-1 gap-4',
);

const gridStyle = computed(() => {
    if (!resizable.value) {
        return {};
    }
    return {
        gridTemplateColumns: `${leftWidth.value}px ${RESIZER_WIDTH}px minmax(0, 1fr)`,
    };
});

function clampWidth(width: number): number {
    const containerWidth = gridEl.value?.clientWidth ?? window.innerWidth;
    const maxLeft = Math.max(
        props.minLeft,
        containerWidth - props.minRight - RESIZER_WIDTH,
    );
    return Math.round(Math.min(Math.max(width, props.minLeft), maxLeft));
}

function persist() {
    try {
        localStorage.setItem(props.storageKey, String(leftWidth.value));
    } catch {
        // localStorage may throw in private mode — non-fatal.
    }
}

function startResize(event: PointerEvent) {
    if (!resizable.value) {
        return;
    }
    event.preventDefault();
    isResizing.value = true;

    const startX = event.clientX;
    const startWidth = leftWidth.value;

    function onMove(e: PointerEvent) {
        leftWidth.value = clampWidth(startWidth + (e.clientX - startX));
    }
    function onUp() {
        isResizing.value = false;
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        document.body.style.userSelect = '';
        document.body.style.cursor = '';
        persist();
    }

    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    document.body.style.userSelect = 'none';
    document.body.style.cursor = 'col-resize';
}

function onResizerKeydown(event: KeyboardEvent) {
    if (!resizable.value) {
        return;
    }
    const step = event.shiftKey ? 48 : 16;
    if (event.key === 'ArrowLeft') {
        leftWidth.value = clampWidth(leftWidth.value - step);
    } else if (event.key === 'ArrowRight') {
        leftWidth.value = clampWidth(leftWidth.value + step);
    } else if (event.key === 'Home') {
        leftWidth.value = clampWidth(props.minLeft);
    } else if (event.key === 'End') {
        leftWidth.value = clampWidth(Number.MAX_SAFE_INTEGER);
    } else {
        return;
    }
    event.preventDefault();
    persist();
}

function reset() {
    leftWidth.value = clampWidth(props.defaultLeftWidth);
    persist();
}

let mediaQuery: MediaQueryList | null = null;
function handleMediaChange(e: MediaQueryListEvent) {
    isLargeScreen.value = e.matches;
}

onMounted(() => {
    try {
        const stored = localStorage.getItem(props.storageKey);
        if (stored) {
            leftWidth.value = Number(stored) || props.defaultLeftWidth;
        }
    } catch {
        // ignore
    }

    mediaQuery = window.matchMedia('(min-width: 1024px)');
    isLargeScreen.value = mediaQuery.matches;
    mediaQuery.addEventListener('change', handleMediaChange);
});

onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', handleMediaChange);
});
</script>

<template>
    <div ref="gridEl" :class="gridClass" :style="gridStyle">
        <template v-if="showLeft">
            <section class="flex min-h-0 min-w-0 flex-col">
                <slot name="left" />
            </section>

            <div
                v-if="resizable"
                role="separator"
                aria-orientation="vertical"
                tabindex="0"
                :aria-label="resizeLabel"
                :aria-valuenow="Math.round(leftWidth)"
                :aria-valuemin="minLeft"
                class="group relative flex cursor-col-resize touch-none items-center justify-center outline-none"
                @pointerdown="startResize"
                @dblclick="reset"
                @keydown="onResizerKeydown"
            >
                <GripVertical
                    class="size-4 transition-colors"
                    :class="
                        isResizing
                            ? 'text-accent-blue'
                            : 'text-ink-muted group-hover:text-accent-blue group-focus-visible:text-accent-blue'
                    "
                />
            </div>
        </template>

        <section class="flex min-h-0 min-w-0 flex-col">
            <slot name="right" />
        </section>
    </div>
</template>
