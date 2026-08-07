<script setup lang="ts">
import { Camera, RotateCcw, X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { runtimeWord } from '../words';
import { drawStamp, stampLines, type StampPoint } from './photoStamp';

/**
 * Taking the photo inside the page, so it can be stamped.
 *
 * Ordinarily a camera field hands over to the phone's own camera app, which
 * focuses and exposes better than anything we would build. This is the
 * exception, and it exists for exactly one reason: the OS gives back a finished
 * JPEG, and there is no moment in that flow where the date and the place can be
 * written INTO the image. For a photo that is evidence — a meter reading,
 * damage on arrival, proof somebody was where they say they were — that moment
 * is the whole point, so here the page holds the shutter.
 *
 * The location is asked for while the viewfinder is open rather than after the
 * shot: a fix takes a few seconds, and asking afterwards means either a photo
 * that waits or a stamp that says where the phone was a moment later.
 */
const props = defineProps<{ locale: string; stamp: boolean }>();

const emit = defineEmits<{
    (e: 'captured', blob: Blob): void;
    (e: 'close'): void;
}>();

const video = ref<HTMLVideoElement | null>(null);
const failed = ref<string | null>(null);
/** The frame the person is looking at before deciding to keep it. */
const preview = ref<string | null>(null);

let stream: MediaStream | null = null;
let shot: Blob | null = null;
let point: StampPoint | null = null;

/** The longest edge we keep. A 12-megapixel photo of a meter is a slow upload. */
const MAX_EDGE = 1600;

async function start(): Promise<void> {
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            // The camera on the BACK. The default is the selfie one, which
            // nobody has ever pointed at a water meter.
            video: { facingMode: 'environment' },
        });
    } catch {
        failed.value = runtimeWord(props.locale, 'camera_denied');

        return;
    }

    const el = video.value;
    if (el === null) return;
    el.srcObject = stream;
    await el.play().catch(() => undefined);

    if (props.stamp) locate();
}

/**
 * Where this is being taken.
 *
 * Started with the viewfinder and never waited on: a refusal, an indoor phone
 * with no fix, ten seconds of nothing — all of them end with a photo carrying
 * the time alone, which is worth more than no photo at all.
 */
function locate(): void {
    if (typeof navigator === 'undefined' || !navigator.geolocation) return;

    navigator.geolocation.getCurrentPosition(
        (position) => {
            point = {
                lat: Number(position.coords.latitude.toFixed(6)),
                lng: Number(position.coords.longitude.toFixed(6)),
                ...(Number.isFinite(position.coords.accuracy)
                    ? { accuracy: Math.round(position.coords.accuracy) }
                    : {}),
            };
        },
        () => undefined,
        { enableHighAccuracy: true, timeout: 10_000 },
    );
}

async function shoot(): Promise<void> {
    const el = video.value;
    if (el === null || el.videoWidth === 0) return;

    const scale = Math.min(
        1,
        MAX_EDGE / Math.max(el.videoWidth, el.videoHeight),
    );
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(el.videoWidth * scale);
    canvas.height = Math.round(el.videoHeight * scale);

    const ctx = canvas.getContext('2d');
    if (ctx === null) return;

    ctx.drawImage(el, 0, 0, canvas.width, canvas.height);

    if (props.stamp) {
        drawStamp(
            ctx,
            canvas.width,
            canvas.height,
            stampLines(new Date(), point, props.locale),
        );
    }

    shot = await new Promise<Blob | null>((resolve) => {
        canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.85);
    });

    if (shot === null) {
        failed.value = runtimeWord(props.locale, 'camera_failed');

        return;
    }

    preview.value = URL.createObjectURL(shot);
}

function retake(): void {
    if (preview.value !== null) URL.revokeObjectURL(preview.value);
    preview.value = null;
    shot = null;
}

function accept(): void {
    if (shot === null) return;
    const blob = shot;
    stop();
    emit('captured', blob);
}

function stop(): void {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    if (preview.value !== null) URL.revokeObjectURL(preview.value);
}

onMounted(start);
onBeforeUnmount(stop);
</script>

<template>
    <div
        data-sp-camera
        class="fixed inset-0 z-50 flex flex-col bg-black/95"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-center justify-between p-4 text-white">
            <span class="text-sm">
                {{
                    runtimeWord(
                        locale,
                        stamp ? 'camera_stamp_hint' : 'camera_aim',
                    )
                }}
            </span>
            <button
                type="button"
                data-sp-camera-close
                class="rounded-pill p-1.5 transition-colors hover:bg-white/10"
                :aria-label="runtimeWord(locale, 'camera_close')"
                @click="
                    stop();
                    emit('close');
                "
            >
                <X class="size-5" />
            </button>
        </div>

        <div class="relative flex flex-1 items-center justify-center">
            <img
                v-if="preview"
                :src="preview"
                alt=""
                class="max-h-full max-w-full"
            />
            <video
                v-show="!preview"
                ref="video"
                class="max-h-full max-w-full"
                muted
                playsinline
            />
        </div>

        <p
            v-if="failed"
            data-sp-camera-failed
            class="p-6 text-center text-sm text-white"
        >
            {{ failed }}
        </p>

        <div v-else class="flex items-center justify-center gap-3 p-6">
            <template v-if="preview">
                <button
                    type="button"
                    data-sp-camera-retake
                    class="inline-flex items-center gap-1.5 rounded-pill bg-white/10 px-4 py-2 text-sm text-white transition-colors hover:bg-white/20"
                    @click="retake"
                >
                    <RotateCcw class="size-4" />
                    {{ runtimeWord(locale, 'camera_retake') }}
                </button>
                <button
                    type="button"
                    data-sp-camera-accept
                    class="rounded-pill bg-white px-5 py-2 text-sm font-medium text-black"
                    @click="accept"
                >
                    {{ runtimeWord(locale, 'camera_use') }}
                </button>
            </template>
            <button
                v-else
                type="button"
                data-sp-camera-shoot
                class="inline-flex items-center gap-2 rounded-pill bg-white px-6 py-3 text-sm font-medium text-black"
                @click="shoot"
            >
                <Camera class="size-5" />
                {{ runtimeWord(locale, 'camera_shoot') }}
            </button>
        </div>
    </div>
</template>
