<script setup lang="ts">
import { Mic, Square, X } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import { runtimeWord } from '../words';

/**
 * Saying the record out loud.
 *
 * MediaRecorder rather than a file input with `capture`, which is the trick the
 * camera uses: on a phone that would open the voice memo app and hand back a
 * file, but on a DESKTOP it opens a file picker, and nobody dictating a
 * delivery note has an audio file lying around. Recording in the page is the
 * only version that works at both ends.
 *
 * Every failure lands in the same place: the sheet closes and the form is still
 * a form somebody can type into.
 */
const props = defineProps<{ locale: string }>();

const emit = defineEmits<{
    (e: 'recorded', blob: Blob): void;
    (e: 'close'): void;
}>();

const recording = ref(false);
const failed = ref<string | null>(null);
const seconds = ref(0);

let recorder: MediaRecorder | null = null;
let stream: MediaStream | null = null;
let chunks: Blob[] = [];
let tick: ReturnType<typeof setInterval> | undefined;

/** Long enough for anything anybody dictates into a form; short enough to bound the bill. */
const MAX_SECONDS = 120;

async function start(): Promise<void> {
    failed.value = null;

    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        failed.value = runtimeWord(props.locale, 'voice_no_mic');

        return;
    }

    chunks = [];
    recorder = new MediaRecorder(stream);
    recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data);
    };
    recorder.onstop = () => {
        cleanup();
        if (chunks.length > 0) {
            emit('recorded', new Blob(chunks, { type: chunks[0].type }));
        }
    };

    recorder.start();
    recording.value = true;
    seconds.value = 0;

    tick = setInterval(() => {
        seconds.value += 1;
        // Somebody walks away mid-sentence, or forgets. Stopping on its own
        // means the recording that arrives is one that ends.
        if (seconds.value >= MAX_SECONDS) stop();
    }, 1000);
}

function stop(): void {
    recording.value = false;
    if (recorder?.state === 'recording') {
        recorder.stop();
    }
}

function cleanup(): void {
    clearInterval(tick);
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    recorder = null;
    recording.value = false;
}

onBeforeUnmount(cleanup);
</script>

<template>
    <div
        data-sp-voice
        class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-black/95 p-6"
        role="dialog"
        aria-modal="true"
    >
        <button
            type="button"
            data-sp-voice-close
            class="absolute top-4 right-4 rounded-pill p-1.5 text-white transition-colors hover:bg-white/10"
            @click="
                cleanup();
                emit('close');
            "
        >
            <X class="size-5" />
        </button>

        <p
            v-if="failed"
            data-sp-voice-failed
            class="text-center text-sm text-white"
        >
            {{ failed }}
        </p>

        <template v-else>
            <p class="text-center text-sm text-white/80">
                {{
                    runtimeWord(
                        locale,
                        recording ? 'voice_listening' : 'voice_hint',
                    )
                }}
            </p>

            <button
                v-if="!recording"
                type="button"
                data-sp-voice-start
                class="flex size-20 items-center justify-center rounded-full bg-accent-blue text-white transition-transform hover:scale-105"
                @click="start"
            >
                <Mic class="size-8" />
            </button>
            <button
                v-else
                type="button"
                data-sp-voice-stop
                class="flex size-20 items-center justify-center rounded-full bg-red-500 text-white transition-transform hover:scale-105"
                @click="stop"
            >
                <Square class="size-7" />
            </button>

            <p v-if="recording" class="font-mono text-xs text-white/60">
                {{ seconds }}s
            </p>
        </template>
    </div>
</template>
