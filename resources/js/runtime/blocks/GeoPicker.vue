<script setup lang="ts">
import { X } from '@lucide/vue';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';

/**
 * Choosing a point by looking at it.
 *
 * The third way into a `geo` field, and the one for everything the other two
 * cannot answer: the delivery address somebody described over the phone, the
 * corner of a plot, the spot on a site plan where the meter actually is. Typing
 * coordinates requires knowing them; standing there requires being there.
 *
 * Loaded lazily by the field, because MapLibre and its tiles are a megabyte
 * that a form with no map has no reason to pay for.
 */
const props = defineProps<{
    locale: string;
    /** Where to open. Null centres on the world rather than pretending. */
    initial: { lat: number; lng: number } | null;
}>();

const emit = defineEmits<{
    (e: 'picked', point: { lat: number; lng: number }): void;
    (e: 'close'): void;
}>();

const theme = useRuntimeTheme();
const container = ref<HTMLElement | null>(null);
const chosen = ref<{ lat: number; lng: number } | null>(props.initial);

let map: maplibregl.Map | null = null;
let marker: maplibregl.Marker | null = null;

// The same free style the map block uses — no API key, dark and light both.
const styleUrl =
    theme === 'light'
        ? 'https://tiles.openfreemap.org/styles/positron'
        : 'https://tiles.openfreemap.org/styles/dark';

function place(lng: number, lat: number): void {
    chosen.value = { lat: Number(lat.toFixed(6)), lng: Number(lng.toFixed(6)) };

    if (marker === null) {
        marker = new maplibregl.Marker({ draggable: true })
            .setLngLat([lng, lat])
            .addTo(map as maplibregl.Map);

        // Dragging is the correction: tapping gets you close, and the last
        // twenty metres are what somebody came here for.
        marker.on('dragend', () => {
            const at = marker!.getLngLat();
            chosen.value = {
                lat: Number(at.lat.toFixed(6)),
                lng: Number(at.lng.toFixed(6)),
            };
        });

        return;
    }

    marker.setLngLat([lng, lat]);
}

onMounted(() => {
    if (container.value === null) return;

    map = new maplibregl.Map({
        container: container.value,
        style: styleUrl,
        center: props.initial
            ? [props.initial.lng, props.initial.lat]
            : [-99.13, 19.43],
        // Close in on a point somebody already chose; wide when there is none,
        // because guessing a neighbourhood is worse than showing a country.
        zoom: props.initial ? 15 : 3,
        attributionControl: { compact: true },
    });

    map.addControl(
        new maplibregl.NavigationControl({ showCompass: false }),
        'top-right',
    );

    map.on('load', () => {
        if (props.initial) place(props.initial.lng, props.initial.lat);
    });

    map.on('click', (event) => place(event.lngLat.lng, event.lngLat.lat));
});

onBeforeUnmount(() => {
    marker?.remove();
    map?.remove();
    map = null;
});
</script>

<template>
    <div
        data-sp-geo-picker
        class="fixed inset-0 z-50 flex flex-col bg-black/95"
        role="dialog"
        aria-modal="true"
    >
        <div class="flex items-center justify-between p-4 text-white">
            <span class="text-sm">
                {{ runtimeWord(locale, 'geo_pick_hint') }}
            </span>
            <button
                type="button"
                data-sp-geo-picker-close
                class="rounded-pill p-1.5 transition-colors hover:bg-white/10"
                @click="emit('close')"
            >
                <X class="size-5" />
            </button>
        </div>

        <div ref="container" class="flex-1" />

        <div class="flex items-center gap-3 p-4">
            <span class="flex-1 font-mono text-xs text-white/70">
                <template v-if="chosen">
                    {{ chosen.lat.toFixed(6) }}, {{ chosen.lng.toFixed(6) }}
                </template>
            </span>
            <button
                type="button"
                data-sp-geo-picker-accept
                :disabled="!chosen"
                class="rounded-pill bg-accent-blue px-3 py-1.5 text-xs text-white transition-opacity disabled:opacity-40"
                @click="chosen && emit('picked', chosen)"
            >
                {{ runtimeWord(locale, 'geo_pick_accept') }}
            </button>
        </div>
    </div>
</template>
