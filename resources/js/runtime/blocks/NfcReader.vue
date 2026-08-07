<script setup lang="ts">
import { Nfc, X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { haptic } from '../device';
import { runtimeWord } from '../words';

/**
 * Reading a tag held against the phone.
 *
 * The barcode's sibling, for the things a printed label cannot survive: a card
 * in a wallet, a tag glued to a machine that lives outdoors, an asset in a
 * place too dark or too greasy for a camera. Web NFC is Chrome on Android and
 * nowhere else, which is fine — this is an extra way in, and the box behind
 * this sheet still takes a typed code.
 *
 * What a tag is called is the tag's business: a text or url record if it
 * carries one, and otherwise the serial number the chip was born with, which is
 * what an unprogrammed asset tag has and is a perfectly good identifier.
 */
const props = defineProps<{ locale: string }>();

const emit = defineEmits<{
    (e: 'read', value: string): void;
    (e: 'close'): void;
}>();

const failed = ref<string | null>(null);

/**
 * Stops the scan when the sheet closes. Without it the reader keeps listening
 * after the dialog is gone, and the next tag anybody waves near the phone lands
 * in a field they are no longer looking at.
 */
let abort: AbortController | null = null;

/** The first thing on the tag that a person would recognise as its name. */
function valueFrom(event: {
    serialNumber?: string;
    message?: { records: Array<{ recordType: string; data?: DataView }> };
}): string | null {
    for (const record of event.message?.records ?? []) {
        if (record.recordType !== 'text' && record.recordType !== 'url') {
            continue;
        }

        try {
            const text = new TextDecoder().decode(record.data).trim();
            if (text !== '') return text;
        } catch {
            // A record in an encoding we cannot read is not the end of the
            // tag — the serial number below still identifies it.
        }
    }

    const serial = event.serialNumber?.trim();

    return serial !== undefined && serial !== '' ? serial : null;
}

async function start(): Promise<void> {
    const Reader = (
        window as unknown as {
            NDEFReader?: new () => {
                scan: (options?: { signal?: AbortSignal }) => Promise<void>;
                onreading: ((event: never) => void) | null;
                onreadingerror: (() => void) | null;
            };
        }
    ).NDEFReader;

    if (Reader === undefined) {
        failed.value = runtimeWord(props.locale, 'nfc_unsupported');

        return;
    }

    abort = new AbortController();
    const reader = new Reader();

    reader.onreading = ((event: {
        serialNumber?: string;
        message?: { records: Array<{ recordType: string; data?: DataView }> };
    }) => {
        const value = valueFrom(event);
        if (value === null) {
            failed.value = runtimeWord(props.locale, 'nfc_empty');

            return;
        }

        // Confirmed in the hand: the phone is against the tag, so the person is
        // not looking at the screen at the moment it reads.
        haptic();
        emit('read', value);
    }) as never;

    reader.onreadingerror = () => {
        failed.value = runtimeWord(props.locale, 'nfc_failed');
    };

    try {
        await reader.scan({ signal: abort.signal });
    } catch {
        // Refused, or NFC turned off in the phone's settings. Named as one
        // thing because the person's next move is the same either way.
        failed.value = runtimeWord(props.locale, 'nfc_denied');
    }
}

function stop(): void {
    abort?.abort();
    abort = null;
}

onMounted(start);
onBeforeUnmount(stop);
</script>

<template>
    <div
        data-sp-nfc
        class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-black/95 p-6 text-white"
        role="dialog"
        aria-modal="true"
    >
        <button
            type="button"
            data-sp-nfc-close
            class="absolute top-4 right-4 rounded-pill p-1.5 transition-colors hover:bg-white/10"
            :aria-label="runtimeWord(locale, 'nfc_close')"
            @click="
                stop();
                emit('close');
            "
        >
            <X class="size-5" />
        </button>

        <Nfc class="size-16" :class="failed ? 'opacity-40' : 'animate-pulse'" />

        <p v-if="failed" data-sp-nfc-failed class="text-center text-sm">
            {{ failed }}
        </p>
        <p v-else class="text-center text-sm">
            {{ runtimeWord(locale, 'nfc_hold') }}
        </p>
    </div>
</template>
