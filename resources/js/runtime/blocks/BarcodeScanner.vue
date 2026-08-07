<script setup lang="ts">
import { Flashlight, X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { haptic } from '../device';
import { runtimeWord } from '../words';

/**
 * Reading a barcode with the camera.
 *
 * The decoder is the browser's own `BarcodeDetector` wherever it exists —
 * Chrome, Edge and Android, which is most of a warehouse. Safari and iOS have
 * none, so a WASM ponyfill with the same API is imported ONLY when the native
 * one is missing: an Android terminal downloads nothing extra, and an iPhone
 * pays for it once.
 *
 * Every way this can fail ends in the same place: the sheet closes and the
 * field is still a text box somebody can type into. A camera that will not open
 * must never be a dead end — it is the rail every capture in this wave follows.
 */
const props = defineProps<{ locale: string }>();

const emit = defineEmits<{
    (e: 'scanned', value: string): void;
    (e: 'close'): void;
}>();

const video = ref<HTMLVideoElement | null>(null);
const failed = ref<string | null>(null);

/**
 * The phone's own lamp.
 *
 * Offered only when the camera says it has one — a front camera and most
 * laptops do not, and a button that does nothing is worse than no button. It
 * matters because the places barcodes live are shelving aisles, van interiors
 * and the back of a meter cupboard, where the camera can see the label but not
 * well enough to decode it, and the person's other hand is holding the box.
 */
const torchAvailable = ref(false);
const torchOn = ref(false);

let stream: MediaStream | null = null;
let stopped = false;

function videoTrack(): MediaStreamTrack | null {
    return stream?.getVideoTracks()[0] ?? null;
}

async function toggleTorch(): Promise<void> {
    const track = videoTrack();
    if (track === null) return;

    const next = !torchOn.value;

    try {
        await track.applyConstraints({
            advanced: [{ torch: next } as unknown as MediaTrackConstraintSet],
        });
        torchOn.value = next;
    } catch {
        // Some devices advertise the capability and then refuse it while the
        // stream is live. Leaving the toggle where it was keeps the button
        // honest about what the lamp is actually doing.
        torchAvailable.value = false;
    }
}

/** The native detector, or a WASM one with the same shape when there is none. */
async function makeDetector(): Promise<{
    detect: (source: CanvasImageSource) => Promise<Array<{ rawValue: string }>>;
} | null> {
    const formats = [
        'qr_code',
        'ean_13',
        'ean_8',
        'code_128',
        'code_39',
        'upc_a',
        'upc_e',
        'itf',
        'data_matrix',
    ];

    const native = (
        window as unknown as { BarcodeDetector?: new (o: unknown) => never }
    ).BarcodeDetector;

    if (typeof native === 'function') {
        return new native({ formats }) as never;
    }

    try {
        // Lazy, and only here: the bundle a phone with a native detector
        // downloads must not carry a decoder it will never run.
        const { BarcodeDetector } = await import('barcode-detector/ponyfill');

        return new BarcodeDetector({ formats: formats as never });
    } catch {
        return null;
    }
}

async function start(): Promise<void> {
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            // The camera on the BACK of the phone. The default is the selfie
            // one, which nobody has ever pointed at a package.
            video: { facingMode: 'environment' },
        });
    } catch {
        failed.value = runtimeWord(props.locale, 'scan_no_camera');

        return;
    }

    const capabilities = videoTrack()?.getCapabilities?.() as
        | { torch?: boolean }
        | undefined;
    torchAvailable.value = capabilities?.torch === true;

    const el = video.value;
    if (el === null) return;
    el.srcObject = stream;
    await el.play().catch(() => undefined);

    const detector = await makeDetector();
    if (detector === null) {
        failed.value = runtimeWord(props.locale, 'scan_unsupported');

        return;
    }

    const tick = async () => {
        if (stopped || video.value === null) return;

        try {
            const found = await detector.detect(video.value);
            const value = found[0]?.rawValue?.trim();
            if (value) {
                // Confirmed in the hand, because the hand is where the phone
                // is: an operator holding it over a pallet cannot also be
                // watching the screen for the sheet to close.
                haptic();
                emit('scanned', value);

                return; // one read per open: the caller decides what happens next
            }
        } catch {
            // A frame that would not decode is the normal case, not an error.
        }

        // Four looks a second: fast enough to feel instant, slow enough to
        // leave the phone's CPU for the camera.
        window.setTimeout(tick, 250);
    };

    tick();
}

function stop(): void {
    stopped = true;
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
}

onMounted(start);
onBeforeUnmount(stop);
</script>

<template>
    <div
        data-sp-scanner
        class="fixed inset-0 z-50 flex flex-col bg-black/95"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-center justify-between p-4 text-white">
            <span class="text-sm">
                {{ runtimeWord(locale, 'scan_aim') }}
            </span>
            <div class="flex items-center gap-1">
                <button
                    v-if="torchAvailable"
                    type="button"
                    data-sp-scanner-torch
                    :aria-pressed="torchOn"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-pill px-2.5 py-1 text-xs transition-colors',
                        torchOn
                            ? 'bg-white text-black'
                            : 'bg-white/10 hover:bg-white/20',
                    ]"
                    @click="toggleTorch"
                >
                    <Flashlight class="size-4" />
                    {{ runtimeWord(locale, 'scan_torch') }}
                </button>
                <button
                    type="button"
                    data-sp-scanner-close
                    class="rounded-pill p-1.5 transition-colors hover:bg-white/10"
                    :aria-label="runtimeWord(locale, 'scan_close')"
                    @click="
                        stop();
                        emit('close');
                    "
                >
                    <X class="size-5" />
                </button>
            </div>
        </div>

        <div class="relative flex flex-1 items-center justify-center">
            <video
                ref="video"
                class="max-h-full max-w-full"
                muted
                playsinline
            />
            <!-- A frame to aim with. Purely a hint: the decoder reads the whole
                 image, so a code outside it still scans. -->
            <div
                v-if="!failed"
                class="pointer-events-none absolute h-32 w-64 rounded-md border-2 border-white/70"
            />
        </div>

        <p
            v-if="failed"
            data-sp-scanner-failed
            class="p-6 text-center text-sm text-white"
        >
            {{ failed }}
        </p>
    </div>
</template>
