<script setup lang="ts">
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface MapBlock {
    id: string;
    type: 'map';
    label?: string;
    data_source: { object_id: string };
    lat_field_id: string;
    lng_field_id: string;
    popup_field_id?: string;
    color_field_id?: string;
    height_px?: number;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: MapBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const theme = useRuntimeTheme();
const t = themeTokens(theme);

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);
function fieldOf(id?: string): FieldDef | undefined {
    if (!id) return undefined;
    return object.value?.fields.find((f) => f.id === id);
}
const latField = computed(() => fieldOf(props.block.lat_field_id));
const lngField = computed(() => fieldOf(props.block.lng_field_id));
const popupField = computed(() => fieldOf(props.block.popup_field_id));
const colorField = computed(() => fieldOf(props.block.color_field_id));

interface Marker {
    lat: number;
    lng: number;
    popup: string | null;
    color: string;
}

const markers = computed<Marker[]>(() => {
    const rows = props.data?.rows ?? [];
    const latSlug = latField.value?.slug;
    const lngSlug = lngField.value?.slug;
    const popupSlug = popupField.value?.slug;
    const colorSlug = colorField.value?.slug;
    if (!latSlug || !lngSlug) return [];

    return rows
        .map<Marker | null>((r) => {
            const lat = Number(r.data[latSlug]);
            const lng = Number(r.data[lngSlug]);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
            let color = '#3B82F6';
            if (colorField.value?.type === 'single_select' && colorSlug) {
                const opt = colorField.value.options?.find((o) => o.value === r.data[colorSlug]);
                if (opt?.color) color = opt.color;
            }
            return {
                lat,
                lng,
                popup: popupSlug ? String(r.data[popupSlug] ?? '') : null,
                color,
            };
        })
        .filter((m): m is Marker => m !== null);
});

const container = ref<HTMLElement | null>(null);
let map: maplibregl.Map | null = null;
let mapMarkers: maplibregl.Marker[] = [];

// Free style from OpenFreeMap — no API key, dark/light variants both available.
const styleUrl = computed(() =>
    theme === 'light'
        ? 'https://tiles.openfreemap.org/styles/positron'
        : 'https://tiles.openfreemap.org/styles/dark',
);

function syncMarkers() {
    if (!map) return;
    mapMarkers.forEach((m) => m.remove());
    mapMarkers = markers.value.map((m) => {
        const el = document.createElement('div');
        el.style.cssText = `width:14px;height:14px;border-radius:50%;background:${m.color};border:2px solid rgba(255,255,255,0.85);box-shadow:0 0 0 1px rgba(0,0,0,0.25)`;
        const marker = new maplibregl.Marker({ element: el }).setLngLat([m.lng, m.lat]);
        if (m.popup) {
            marker.setPopup(new maplibregl.Popup({ offset: 12 }).setText(m.popup));
        }
        marker.addTo(map!);
        return marker;
    });

    if (markers.value.length > 0) {
        const bounds = new maplibregl.LngLatBounds();
        markers.value.forEach((m) => bounds.extend([m.lng, m.lat]));
        map.fitBounds(bounds, { padding: 40, maxZoom: 13, duration: 0 });
    }
}

onMounted(() => {
    if (!container.value) return;
    map = new maplibregl.Map({
        container: container.value,
        style: styleUrl.value,
        center: [-99.13, 19.43], // CDMX fallback if there are no markers
        zoom: 4,
        attributionControl: { compact: true },
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.on('load', syncMarkers);
});

watch(markers, () => syncMarkers());

onBeforeUnmount(() => {
    mapMarkers.forEach((m) => m.remove());
    mapMarkers = [];
    map?.remove();
    map = null;
});
</script>

<template>
    <div :class="['overflow-hidden rounded-sp-sm border', t.surface]">
        <header v-if="block.label" class="border-b border-soft px-4 py-2">
            <p :class="['text-[11px] uppercase tracking-wider', t.textSubtle]">{{ block.label }}</p>
        </header>
        <div
            ref="container"
            class="w-full"
            :style="{ height: (block.height_px ?? 400) + 'px' }"
        />
    </div>
</template>
