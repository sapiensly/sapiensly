<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';

/**
 * Somebody's name, written with a finger.
 *
 * Pointer events rather than mouse or touch ones: one code path covers a
 * finger, a stylus and a trackpad, and it is the only way a tablet with a pen
 * behaves the same as a phone.
 *
 * Black ink on transparency, deliberately, and never the app's theme colour: a
 * signature is evidence, it gets printed onto delivery notes and composited
 * into PDFs, and a signature that came out white on a dark-themed app would be
 * invisible on paper. The canvas is drawn on white here so the person can see
 * what they are signing.
 */
const props = defineProps<{
    locale: string;
    /** Disabled while the last one is still uploading. */
    busy?: boolean;
}>();

const emit = defineEmits<{ (e: 'signed', blob: Blob): void }>();

const theme = useRuntimeTheme();
const t = themeTokens(theme);

const canvas = ref<HTMLCanvasElement | null>(null);
const hasInk = ref(false);

let ctx: CanvasRenderingContext2D | null = null;
let drawing = false;

/**
 * The canvas is sized in DEVICE pixels and scaled back down, or the stroke is a
 * blurred double-width smear on every phone made since 2014.
 */
function fit(): void {
    const el = canvas.value;
    if (!el) return;

    const ratio = window.devicePixelRatio || 1;
    const rect = el.getBoundingClientRect();
    if (rect.width === 0) return;

    el.width = Math.round(rect.width * ratio);
    el.height = Math.round(rect.height * ratio);

    ctx = el.getContext('2d');
    if (!ctx) return;

    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';
}

function pointOf(e: PointerEvent): { x: number; y: number } {
    const rect = (canvas.value as HTMLCanvasElement).getBoundingClientRect();

    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
}

function start(e: PointerEvent): void {
    if (props.busy || !ctx) return;
    // Keeps the stroke coming to us if the finger leaves the canvas mid-signature.
    (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
    drawing = true;
    hasInk.value = true;
    const p = pointOf(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
}

function move(e: PointerEvent): void {
    if (!drawing || !ctx) return;
    // The browser would otherwise scroll the page while somebody signs on it.
    e.preventDefault();
    const p = pointOf(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
}

function end(): void {
    drawing = false;
}

function clear(): void {
    const el = canvas.value;
    if (!el || !ctx) return;
    ctx.clearRect(0, 0, el.width, el.height);
    hasInk.value = false;
}

function accept(): void {
    const el = canvas.value;
    if (!el || !hasInk.value) return;

    el.toBlob((blob) => {
        if (blob) emit('signed', blob);
    }, 'image/png');
}

onMounted(() => {
    fit();
    window.addEventListener('resize', fit);
});

onBeforeUnmount(() => window.removeEventListener('resize', fit));
</script>

<template>
    <div class="space-y-2">
        <canvas
            ref="canvas"
            data-sp-signature-pad
            class="h-32 w-full touch-none rounded-md border-2 border-dashed bg-white"
            @pointerdown="start"
            @pointermove="move"
            @pointerup="end"
            @pointercancel="end"
            @pointerleave="end"
        />
        <div class="flex items-center gap-2">
            <span :class="['flex-1 text-[10px]', t.textMuted]">
                {{ runtimeWord(locale, 'signature_hint') }}
            </span>
            <button
                type="button"
                data-sp-signature-clear
                :disabled="!hasInk || busy"
                :class="[
                    'rounded-pill px-2.5 py-1 text-xs transition-colors hover:bg-surface-hover disabled:opacity-40',
                    t.textMuted,
                ]"
                @click="clear"
            >
                {{ runtimeWord(locale, 'signature_clear') }}
            </button>
            <button
                type="button"
                data-sp-signature-accept
                :disabled="!hasInk || busy"
                class="rounded-pill bg-accent-blue px-2.5 py-1 text-xs text-white transition-opacity disabled:opacity-40"
                @click="accept"
            >
                {{ runtimeWord(locale, 'signature_accept') }}
            </button>
        </div>
    </div>
</template>
