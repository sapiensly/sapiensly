<script setup lang="ts">
/**
 * A picture, at the size it was taken.
 *
 * A thumbnail is enough to know WHICH photo it is and never enough to read a
 * meter, check a serial number or see whether a signature is really a
 * signature — which is the whole reason the field exists. So the thumbnail is
 * a way in, and this is what it opens.
 *
 * Deliberately not the shared Dialog: that one is a form container with
 * padding, a surface colour and a card that becomes the screen below sm. A
 * photo wants the opposite — the dark, nothing else, and the image as large as
 * it goes. Forty lines here beats bending a component built for the other job.
 */
import { X } from '@lucide/vue';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps<{ src: string; alt?: string }>();

const emit = defineEmits<{ (e: 'close'): void }>();

/**
 * Escape closes it, because a viewer with one way out is a viewer somebody gets
 * stuck in — and on a phone the browser's back gesture is the other one people
 * reach for, which lands them off the record entirely.
 */
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-[110] flex items-center justify-center bg-black/90 p-4"
            role="dialog"
            aria-modal="true"
            data-sp-lightbox
            @click="emit('close')"
        >
            <!-- `contain` rather than a width: a portrait photo and a wide
                 signature are both whole, neither cropped nor stretched. -->
            <img
                :src="props.src"
                :alt="props.alt ?? ''"
                class="max-h-full max-w-full object-contain"
                @click.stop
            />

            <button
                type="button"
                class="absolute top-4 right-4 rounded-full bg-black/50 p-2 text-white/80 transition-colors hover:text-white"
                :aria-label="'Close'"
                @click="emit('close')"
            >
                <X class="size-5" />
            </button>
        </div>
    </Teleport>
</template>
