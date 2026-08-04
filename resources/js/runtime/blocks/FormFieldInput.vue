<script setup lang="ts">
import { Camera, Map, MapPin, ScanLine } from '@lucide/vue';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { requestScan } from '../scanner';
import type { FieldDef } from '../types/manifest';
import { useFileUpload, type UploadedFile } from '../useFileUpload';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import { runtimeWord } from '../words';
import SignaturePad from './SignaturePad.vue';

/**
 * Lazy on purpose: MapLibre and its style are about a megabyte, and a form that
 * never opens the picker has no reason to download them.
 */
const GeoPicker = defineAsyncComponent(() => import('./GeoPicker.vue'));
/**
 * Loaded only by a form that actually has a rich_text field.
 *
 * Imported statically it rode along with EVERY form: opening a modal to type a
 * subject and an email pulled the editor, tiptap's starter kit, tiptap's Vue
 * bindings and their chunks — six of the ten modules the dialog fetched, none
 * of which it could use. That is the pause between a modal's title appearing
 * and its fields showing up.
 */
const RichTextEditor = defineAsyncComponent(
    () => import('./RichTextEditor.vue'),
);

import RelationPicker from './RelationPicker.vue';

const props = defineProps<{
    /** Field descriptor from the manifest. Drives which input is rendered. */
    field: FieldDef;
    /** Stable id for the <label for=…>. Unique per block + field. */
    inputId: string;
    /** Current value (parent owns the form state). */
    modelValue: unknown;
    /** Slug used by the App's upload and options endpoints. */
    appSlug: string;
    /** The app's locale, for the words the inputs say on their own behalf. */
    locale?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: unknown): void;
}>();

const t = themeTokens(useRuntimeTheme());

// Typed view of a date_range value so the template reads from/to without casts.
const range = computed(
    () => (props.modelValue ?? {}) as { from?: string; to?: string },
);

function update(value: unknown) {
    emit('update:modelValue', value);
}

/** Two-way binding for HTML inputs. */
function onInput(ev: Event) {
    const target = ev.target as HTMLInputElement;
    update(target.value);
}

function onChecked(ev: Event) {
    update((ev.target as HTMLInputElement).checked);
}

function onNumber(ev: Event) {
    const v = (ev.target as HTMLInputElement).valueAsNumber;
    update(Number.isNaN(v) ? null : v);
}

// Rating helpers — clicking the same star clears it.
function toggleRating(value: number) {
    update((props.modelValue as number) === value ? 0 : value);
}

// Slider value formatting.
function formatSliderValue(value: number): string {
    const f = props.field as { format?: string; currency_code?: string };
    const fmt = f.format ?? 'plain';
    if (fmt === 'percentage') return `${value}%`;
    if (fmt === 'currency') {
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: f.currency_code ?? 'MXN',
                maximumFractionDigits: 0,
            }).format(value);
        } catch {
            return String(value);
        }
    }
    return String(value);
}

// date_range — model is an object {from, to}. Patch one side at a time.
function patchRange(side: 'from' | 'to', ev: Event) {
    const v = (ev.target as HTMLInputElement).value;
    const current = (props.modelValue as { from?: string; to?: string }) ?? {
        from: '',
        to: '',
    };
    update({ ...current, [side]: v });
}

/**
 * Whether this field wants the DEVICE rather than the disk.
 *
 * `capture: 'camera'` uses the browser's own capture attribute rather than a
 * camera we open ourselves: on a phone that hands over to the OS camera app,
 * which focuses, exposes and compresses better than anything we would build,
 * and asks for permission in the way the person already recognises. On a
 * desktop the attribute is ignored and the file picker opens — which is the
 * right thing to happen, so the field never becomes a dead end on the wrong
 * device.
 */
const wantsCamera = computed(() => props.field.capture === 'camera');

/**
 * A code read off a label rather than typed.
 *
 * An option on `string` and not a type of its own, for the same reason the
 * camera is an option on `file`: the VALUE is a string either way, so tables,
 * filters, exports and validation all keep working untouched.
 */
const wantsBarcode = computed(() => props.field.capture === 'barcode');

const gunScanned = ref(false);

async function openScanner(): Promise<void> {
    const value = await requestScan(props.locale ?? 'en');
    if (value !== null) update(value);
}

/**
 * A handheld scanner is a keyboard.
 *
 * It types the code far faster than a person can and finishes with Enter — so a
 * burst of sub-30ms keystrokes ending in Enter is a scan, not typing. Worth
 * detecting because it is what warehouses actually use: the operator never
 * touches the screen, and swallowing that Enter keeps a half-filled form from
 * submitting itself between two scans.
 */
let lastKeyAt = 0;
let fastKeys = 0;

function onKeyForGun(event: KeyboardEvent): void {
    if (!wantsBarcode.value) return;

    const now = performance.now();
    const gap = now - lastKeyAt;
    lastKeyAt = now;

    if (event.key === 'Enter') {
        const wasGun = fastKeys >= 3;
        fastKeys = 0;

        if (wasGun) {
            // Not a submit. The next scan should land in this same field.
            event.preventDefault();
            gunScanned.value = true;
            window.setTimeout(() => (gunScanned.value = false), 1500);
        }

        return;
    }

    fastKeys = gap < 30 ? fastKeys + 1 : 0;
}

/**
 * A point on the earth: {lat, lng}, and {accuracy} when the device offered one.
 *
 * "Within 8 metres" and "within 3 kilometres" are very different claims about
 * the same coordinates, and only the device knows which one it made — so the
 * number it reports is kept rather than thrown away.
 */
interface GeoValue {
    lat: number;
    lng: number;
    accuracy?: number;
}

const locating = ref(false);
const picking = ref(false);

/** Where the picker opens: on the point already chosen, or nowhere. */
const pickerStart = computed<{ lat: number; lng: number } | null>(() => {
    const point = props.modelValue as GeoValue | null | undefined;

    return typeof point?.lat === 'number' && typeof point?.lng === 'number'
        ? { lat: point.lat, lng: point.lng }
        : null;
});

function onPicked(point: { lat: number; lng: number }): void {
    picking.value = false;
    geoText.value = { lat: String(point.lat), lng: String(point.lng) };
    update(point);
}

const geoError = ref<string | null>(null);

/**
 * The two halves live HERE, as text, not derived from the model.
 *
 * Filling both in the same tick read a stale `modelValue` on the second one and
 * sent a point with a null longitude — the server refused it, correctly, and
 * the person had typed a perfectly good coordinate. Anyone pasting both, or a
 * script filling the form, hits it every time; someone typing slowly never
 * does, which is exactly the kind of bug that ships.
 *
 * As text, too, because "19." is a real thing to have typed halfway through and
 * a number input that rewrites it under the cursor is unusable.
 */
const geoText = ref<{ lat: string; lng: string }>({ lat: '', lng: '' });

watch(
    () => props.modelValue,
    (value) => {
        const point = value as GeoValue | null | undefined;
        const incoming = {
            lat: typeof point?.lat === 'number' ? String(point.lat) : '',
            lng: typeof point?.lng === 'number' ? String(point.lng) : '',
        };

        // Only when it really differs, or every keystroke would echo back and
        // fight the cursor.
        if (
            Number(incoming.lat) !== Number(geoText.value.lat) ||
            Number(incoming.lng) !== Number(geoText.value.lng)
        ) {
            geoText.value = incoming;
        }
    },
    { immediate: true },
);

function geoPart(part: 'lat' | 'lng'): string {
    return geoText.value[part];
}

/**
 * Half a coordinate is not a place, so nothing is stored until BOTH halves are
 * numbers — and emptying either one clears the field rather than leaving a
 * record that claims to be somewhere at longitude zero.
 */
function setGeoPart(part: 'lat' | 'lng', raw: string): void {
    geoText.value = { ...geoText.value, [part]: raw };

    const lat = Number(geoText.value.lat);
    const lng = Number(geoText.value.lng);
    const both =
        geoText.value.lat.trim() !== '' &&
        geoText.value.lng.trim() !== '' &&
        Number.isFinite(lat) &&
        Number.isFinite(lng);

    update(both ? { lat, lng } : null);
}

async function locate(): Promise<void> {
    geoError.value = null;

    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        geoError.value = runtimeWord(props.locale ?? 'en', 'geo_unavailable');

        return;
    }

    locating.value = true;

    navigator.geolocation.getCurrentPosition(
        (position) => {
            locating.value = false;
            const lat = Number(position.coords.latitude.toFixed(6));
            const lng = Number(position.coords.longitude.toFixed(6));
            geoText.value = { lat: String(lat), lng: String(lng) };
            update({
                lat,
                lng,
                ...(Number.isFinite(position.coords.accuracy)
                    ? { accuracy: Math.round(position.coords.accuracy) }
                    : {}),
            });
        },
        () => {
            // Refused, or no fix. Not a dead end: the boxes beside the button
            // are still there and still take a typed coordinate.
            locating.value = false;
            geoError.value = runtimeWord(props.locale ?? 'en', 'geo_denied');
        },
        { enableHighAccuracy: true, timeout: 10_000 },
    );
}

/** Drawn rather than chosen — the bytes come from a canvas, not the disk. */
const wantsSignature = computed(() => props.field.capture === 'signature');

/**
 * A camera field is asking for a photo, so it says so — unless the author was
 * more specific, in which case they meant it.
 */
const acceptAttr = computed<string | undefined>(() => {
    const declared = (props.field.mime_types ?? []).join(',');
    if (declared !== '') return declared;

    return wantsCamera.value ? 'image/*' : undefined;
});

/**
 * Getting bytes to the server. Shared with the signature pad below and with
 * whatever captures come next — see useFileUpload for why it lives out there.
 */
const {
    progress: uploadProgress,
    error: uploadError,
    upload,
    reset: resetUpload,
} = useFileUpload(props.appSlug);

async function onFileSelected(ev: Event) {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const uploaded = await upload(file);
    if (uploaded !== null) update(uploaded);

    input.value = '';
}

/**
 * A finished signature is a PNG like any other, so from here on it IS a file:
 * same upload, same storage, same preview, same value in the record.
 */
async function onSigned(blob: Blob) {
    const uploaded = await upload(blob, 'firma.png');
    if (uploaded !== null) update(uploaded);
}

function clearFile() {
    update(null);
    resetUpload();
}

function isImageMime(mime?: string): boolean {
    return !!mime && mime.startsWith('image/');
}

function humanSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

// Multi-select handler: convert from CheckboxList to array.
function toggleMultiSelect(value: string) {
    const arr = Array.isArray(props.modelValue)
        ? [...(props.modelValue as string[])]
        : [];
    const i = arr.indexOf(value);
    if (i >= 0) arr.splice(i, 1);
    else arr.push(value);
    update(arr);
}

function isInMulti(value: string): boolean {
    return (
        Array.isArray(props.modelValue) &&
        (props.modelValue as string[]).includes(value)
    );
}
</script>

<template>
    <template v-if="field.type === 'long_text'">
        <textarea
            :id="inputId"
            :value="(modelValue as string) ?? ''"
            @input="onInput"
            rows="3"
            :class="[
                'w-full rounded-md border px-3 py-2 text-sm',
                t.surfaceMuted,
                t.text,
            ]"
        />
    </template>

    <template v-else-if="field.type === 'number' || field.type === 'currency'">
        <input
            :id="inputId"
            :value="modelValue ?? ''"
            @input="onNumber"
            type="number"
            :step="field.type === 'currency' ? '0.01' : 'any'"
            :class="[
                'h-9 w-full rounded-md border px-3 text-sm',
                t.surfaceMuted,
                t.text,
            ]"
        />
    </template>

    <template v-else-if="field.type === 'boolean'">
        <button
            v-if="field.display === 'switch'"
            :id="inputId"
            type="button"
            role="switch"
            :aria-checked="!!modelValue"
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
            :style="{
                background: modelValue
                    ? 'var(--sp-accent, #3b82f6)'
                    : 'color-mix(in srgb, currentColor 25%, transparent)',
            }"
            @click="update(!modelValue)"
        >
            <span
                class="inline-block size-4 rounded-full bg-white transition-transform"
                :class="modelValue ? 'translate-x-4' : 'translate-x-0.5'"
            />
        </button>
        <input
            v-else
            :id="inputId"
            :checked="!!modelValue"
            @change="onChecked"
            type="checkbox"
            class="size-4 rounded border-medium"
        />
    </template>

    <template v-else-if="field.type === 'color'">
        <div class="flex items-center gap-2">
            <input
                :value="(modelValue as string) || '#3b82f6'"
                @input="onInput"
                type="color"
                :class="[
                    'h-9 w-12 shrink-0 cursor-pointer rounded-md border',
                    t.surfaceMuted,
                ]"
            />
            <input
                :id="inputId"
                :value="(modelValue as string) ?? ''"
                @input="onInput"
                type="text"
                placeholder="#RRGGBB"
                :class="[
                    'h-9 w-full rounded-md border px-3 text-sm',
                    t.surfaceMuted,
                    t.text,
                ]"
            />
        </div>
    </template>

    <template v-else-if="field.type === 'date'">
        <input
            :id="inputId"
            :value="(modelValue as string) ?? ''"
            @input="onInput"
            type="date"
            :class="[
                'h-9 w-full rounded-md border px-3 text-sm',
                t.surfaceMuted,
                t.text,
            ]"
        />
    </template>

    <template v-else-if="field.type === 'datetime'">
        <input
            :id="inputId"
            :value="(modelValue as string) ?? ''"
            @input="onInput"
            type="datetime-local"
            :class="[
                'h-9 w-full rounded-md border px-3 text-sm',
                t.surfaceMuted,
                t.text,
            ]"
        />
    </template>

    <template v-else-if="field.type === 'single_select'">
        <div v-if="field.display === 'radio'" class="flex flex-col gap-1.5">
            <label
                v-for="opt in field.options ?? []"
                :key="opt.id"
                class="inline-flex cursor-pointer items-center gap-2 text-sm"
            >
                <input
                    type="radio"
                    :name="inputId"
                    :value="opt.value"
                    :checked="modelValue === opt.value"
                    class="size-4"
                    @change="update(opt.value)"
                />
                {{ opt.label }}
            </label>
        </div>
        <select
            v-else
            :id="inputId"
            :value="(modelValue as string) ?? ''"
            @change="onInput"
            :class="[
                'h-9 w-full rounded-md border px-3 text-sm',
                t.surfaceMuted,
                t.text,
            ]"
        >
            <option value="">—</option>
            <option
                v-for="opt in field.options ?? []"
                :key="opt.id"
                :value="opt.value"
            >
                {{ opt.label }}
            </option>
        </select>
    </template>

    <template v-else-if="field.type === 'multi_select'">
        <div class="flex flex-wrap gap-2">
            <button
                v-for="opt in field.options ?? []"
                :key="opt.id"
                type="button"
                @click="toggleMultiSelect(opt.value)"
                :class="[
                    'inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[11px] transition-colors',
                    isInMulti(opt.value)
                        ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                        : 'border-medium bg-surface text-ink-muted hover:border-strong',
                ]"
            >
                {{ opt.label }}
            </button>
        </div>
    </template>

    <template v-else-if="field.type === 'rating'">
        <div class="flex items-center gap-1">
            <button
                v-for="n in field.max ?? 5"
                :key="n"
                type="button"
                @click="toggleRating(n)"
                :class="[
                    'text-xl leading-none transition-colors',
                    (modelValue as number) >= n
                        ? 'text-amber-400'
                        : 'text-ink-subtle hover:text-amber-400/60',
                ]"
                :title="`${n} of ${field.max ?? 5}`"
            >
                {{
                    field.icon === 'heart'
                        ? '♥'
                        : field.icon === 'thumb'
                          ? '👍'
                          : '★'
                }}
            </button>
            <span :class="['ml-2 text-xs', t.textMuted]">
                {{ modelValue }} /
                {{ field.max ?? 5 }}
            </span>
        </div>
    </template>

    <template v-else-if="field.type === 'slider'">
        <div class="space-y-1">
            <input
                :id="inputId"
                :value="modelValue"
                @input="onNumber"
                type="range"
                :min="field.min ?? 0"
                :max="field.max ?? 100"
                :step="field.step ?? 1"
                class="w-full accent-accent-blue"
            />
            <div :class="['flex justify-between text-[10px]', t.textSubtle]">
                <span>{{ formatSliderValue(field.min ?? 0) }}</span>
                <span :class="['font-semibold', t.text]">
                    {{ formatSliderValue((modelValue as number) ?? 0) }}
                </span>
                <span>{{ formatSliderValue(field.max ?? 100) }}</span>
            </div>
        </div>
    </template>

    <template v-else-if="field.type === 'date_range'">
        <div class="flex items-center gap-2">
            <input
                :id="`${inputId}_from`"
                :value="range.from ?? ''"
                @input="patchRange('from', $event)"
                :type="field.include_time ? 'datetime-local' : 'date'"
                :class="[
                    'h-9 flex-1 rounded-md border px-3 text-sm',
                    t.surfaceMuted,
                    t.text,
                ]"
            />
            <span :class="['text-xs', t.textSubtle]">→</span>
            <input
                :id="`${inputId}_to`"
                :value="range.to ?? ''"
                @input="patchRange('to', $event)"
                :type="field.include_time ? 'datetime-local' : 'date'"
                :class="[
                    'h-9 flex-1 rounded-md border px-3 text-sm',
                    t.surfaceMuted,
                    t.text,
                ]"
            />
        </div>
    </template>

    <template v-else-if="field.type === 'file'">
        <div class="space-y-2">
            <!-- Drawn, not chosen. Once accepted it is a PNG like any other,
                 so the preview below is the same one every file gets. -->
            <SignaturePad
                v-if="wantsSignature && !modelValue"
                :locale="locale ?? 'en'"
                :busy="uploadProgress > 0 && uploadProgress < 100"
                @signed="onSigned"
            />
            <template v-else-if="!modelValue">
                <label
                    :for="inputId"
                    :class="[
                        'flex h-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed text-xs transition-colors',
                        t.surfaceMuted,
                        t.textMuted,
                        'hover:border-accent-blue/40 hover:text-ink',
                    ]"
                >
                    <span v-if="uploadProgress > 0 && uploadProgress < 100">
                        {{
                            runtimeWord(locale ?? 'en', 'file_uploading', {
                                n: uploadProgress,
                            })
                        }}
                    </span>
                    <template v-else>
                        <span class="inline-flex items-center gap-1.5">
                            <Camera v-if="wantsCamera" class="size-3.5" />
                            {{
                                runtimeWord(
                                    locale ?? 'en',
                                    wantsCamera
                                        ? 'file_take_photo'
                                        : 'file_upload',
                                )
                            }}
                        </span>
                        <span class="text-[10px] opacity-60">
                            <template v-if="wantsCamera">
                                {{
                                    runtimeWord(
                                        locale ?? 'en',
                                        'file_photo_hint',
                                    )
                                }}
                                ·
                            </template>
                            Max
                            {{ field.max_size_mb ?? 10 }}MB
                            <template v-if="field.mime_types?.length">
                                ·
                                {{ (field.mime_types ?? []).join(', ') }}
                            </template>
                        </span>
                    </template>
                    <input
                        :id="inputId"
                        type="file"
                        class="hidden"
                        :accept="acceptAttr"
                        :capture="wantsCamera ? 'environment' : undefined"
                        @change="onFileSelected"
                    />
                </label>
            </template>
            <template v-else>
                <div
                    :class="[
                        'flex items-center gap-3 rounded-md border p-2',
                        t.surfaceMuted,
                    ]"
                >
                    <img
                        v-if="isImageMime((modelValue as UploadedFile).mime)"
                        :src="(modelValue as UploadedFile).url"
                        :alt="(modelValue as UploadedFile).original_name"
                        class="size-12 rounded object-cover"
                    />
                    <div
                        v-else
                        :class="[
                            'flex size-12 items-center justify-center rounded font-mono text-[10px] uppercase',
                            t.surface,
                            t.textMuted,
                        ]"
                    >
                        {{
                            (modelValue as UploadedFile).mime
                                .split('/')[1]
                                ?.slice(0, 4) ?? 'FILE'
                        }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <a
                            :href="(modelValue as UploadedFile).url"
                            target="_blank"
                            rel="noopener"
                            :class="[
                                'block truncate text-xs font-medium underline',
                                t.text,
                            ]"
                        >
                            {{ (modelValue as UploadedFile).original_name }}
                        </a>
                        <p :class="['text-[10px]', t.textSubtle]">
                            {{
                                humanSize(
                                    (modelValue as UploadedFile).size_bytes,
                                )
                            }}
                            · {{ (modelValue as UploadedFile).mime }}
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="clearFile"
                        :class="[
                            'rounded p-1 text-xs',
                            t.textMuted,
                            'hover:text-red-400',
                        ]"
                        title="Remove file"
                    >
                        ✕
                    </button>
                </div>
            </template>
            <p v-if="uploadError" class="text-[11px] text-red-400">
                {{ uploadError }}
            </p>
        </div>
    </template>

    <template v-else-if="field.type === 'rich_text'">
        <RichTextEditor
            :model-value="(modelValue as string) ?? ''"
            @update:model-value="update"
            :input-id="inputId"
        />
    </template>

    <!-- A relation is an id, and an id is not something anybody types. This
         used to fall through to the text box below, which is how an app that
         modelled its links could not be used to make one. -->
    <template v-else-if="field.type === 'relation'">
        <RelationPicker
            :field="field"
            :input-id="inputId"
            :model-value="modelValue"
            :app-slug="appSlug"
            :locale="locale"
            @update:model-value="update"
        />
    </template>

    <!-- Fall-through text input: string and the contact trio, which get the
         matching native input type (mobile keyboards + browser validation). -->
    <template v-else-if="field.type === 'geo'">
        <div class="flex flex-wrap items-center gap-2">
            <!-- Typed OR captured, always both. The button is a convenience on
                 a phone; on a desktop, with permission refused, or when the
                 coordinates came off a survey, the boxes are the way in. -->
            <input
                :id="inputId"
                type="number"
                step="any"
                inputmode="decimal"
                data-sp-geo-lat
                :value="geoPart('lat')"
                :placeholder="runtimeWord(locale ?? 'en', 'geo_lat')"
                :class="[
                    'h-9 w-32 rounded-md border px-2 text-sm',
                    t.surfaceMuted,
                    t.text,
                ]"
                @input="
                    setGeoPart('lat', ($event.target as HTMLInputElement).value)
                "
            />
            <input
                type="number"
                step="any"
                inputmode="decimal"
                data-sp-geo-lng
                :value="geoPart('lng')"
                :placeholder="runtimeWord(locale ?? 'en', 'geo_lng')"
                :class="[
                    'h-9 w-32 rounded-md border px-2 text-sm',
                    t.surfaceMuted,
                    t.text,
                ]"
                @input="
                    setGeoPart('lng', ($event.target as HTMLInputElement).value)
                "
            />
            <button
                type="button"
                data-sp-geo-pick
                :class="[
                    'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-xs transition-colors hover:bg-surface-hover',
                    t.surfaceMuted,
                    t.textMuted,
                ]"
                @click="picking = true"
            >
                <Map class="size-3.5" />
                {{ runtimeWord(locale ?? 'en', 'geo_pick') }}
            </button>
            <button
                type="button"
                data-sp-geo-locate
                :disabled="locating"
                :class="[
                    'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-xs transition-colors hover:bg-surface-hover disabled:opacity-50',
                    t.surfaceMuted,
                    t.textMuted,
                ]"
                @click="locate"
            >
                <MapPin class="size-3.5" />
                {{
                    runtimeWord(
                        locale ?? 'en',
                        locating ? 'geo_locating' : 'geo_locate',
                    )
                }}
            </button>
        </div>

        <GeoPicker
            v-if="picking"
            :locale="locale ?? 'en'"
            :initial="pickerStart"
            @picked="onPicked"
            @close="picking = false"
        />

        <p
            v-if="geoError"
            data-sp-geo-error
            class="mt-1 text-[10px] text-amber-500"
        >
            {{ geoError }}
        </p>
    </template>

    <template v-else>
        <div class="flex items-center gap-2">
            <input
                :id="inputId"
                :value="(modelValue as string) ?? ''"
                @input="onInput"
                @keydown="onKeyForGun"
                :type="
                    field.type === 'email'
                        ? 'email'
                        : field.type === 'url'
                          ? 'url'
                          : field.type === 'phone'
                            ? 'tel'
                            : 'text'
                "
                :class="[
                    'h-9 w-full rounded-md border px-3 text-sm',
                    t.surfaceMuted,
                    t.text,
                ]"
            />
            <!-- The camera is an EXTRA way in, never the only one: the box
                 beside it still takes a typed code, which is what happens on a
                 desktop, with a dead camera, or with a damaged label. -->
            <button
                v-if="wantsBarcode"
                type="button"
                data-sp-scan-open
                :class="[
                    'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-xs transition-colors hover:bg-surface-hover',
                    t.surfaceMuted,
                    t.textMuted,
                ]"
                @click="openScanner"
            >
                <ScanLine class="size-3.5" />
                {{ runtimeWord(locale ?? 'en', 'scan_button') }}
            </button>
        </div>

        <p
            v-if="wantsBarcode && gunScanned"
            data-sp-scan-captured
            class="mt-1 text-[10px] text-emerald-500"
        >
            {{ runtimeWord(locale ?? 'en', 'scan_captured') }}
        </p>
    </template>
</template>
