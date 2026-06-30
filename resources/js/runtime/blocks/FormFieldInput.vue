<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import type { FieldDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import RichTextEditor from './RichTextEditor.vue';

interface UploadedFile {
    file_id: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    url: string;
}

const props = defineProps<{
    /** Field descriptor from the manifest. Drives which input is rendered. */
    field: FieldDef;
    /** Stable id for the <label for=…>. Unique per block + field. */
    inputId: string;
    /** Current value (parent owns the form state). */
    modelValue: unknown;
    /** Slug used by the App's upload endpoint (only needed for `file` type). */
    appSlug: string;
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

// File upload state — local to this input.
const uploadProgress = ref(0);
const uploadError = ref<string | null>(null);

async function onFileSelected(ev: Event) {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    uploadError.value = null;
    uploadProgress.value = 0;
    try {
        const form = new FormData();
        form.append('file', file);
        const { data } = await axios.post<UploadedFile>(
            `/r/${props.appSlug}/uploads`,
            form,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
                onUploadProgress: (e) => {
                    if (e.total)
                        uploadProgress.value = Math.round(
                            (e.loaded / e.total) * 100,
                        );
                },
            },
        );
        update(data);
        uploadProgress.value = 100;
    } catch (e) {
        const err = e as {
            response?: { data?: { message?: string } };
            message?: string;
        };
        uploadError.value =
            err.response?.data?.message ?? err.message ?? 'Upload failed.';
    } finally {
        input.value = '';
    }
}

function clearFile() {
    update(null);
    uploadProgress.value = 0;
    uploadError.value = null;
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
            <template v-if="!modelValue">
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
                        Uploading {{ uploadProgress }}%…
                    </span>
                    <template v-else>
                        <span>Click to upload</span>
                        <span class="text-[10px] opacity-60">
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
                        :accept="
                            (field.mime_types ?? []).join(',') || undefined
                        "
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

    <template v-else>
        <input
            :id="inputId"
            :value="(modelValue as string) ?? ''"
            @input="onInput"
            type="text"
            :class="[
                'h-9 w-full rounded-md border px-3 text-sm',
                t.surfaceMuted,
                t.text,
            ]"
        />
    </template>
</template>
