<script setup lang="ts">
import { computed, inject } from 'vue';
import RuntimeIcon from '../RuntimeIcon.vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { useActionExecutor, type RuntimeAction } from '../useActionExecutor';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface CardGridBlock {
    id: string;
    type: 'card_grid';
    data_source: { object_id: string };
    columns?: number;
    title_field_id: string;
    subtitle_field_id?: string;
    image_field_id?: string;
    meta_fields?: Array<{ field_id: string }>;
    /** When set, a card is clickable and fires this sequence with {{row.*}}. */
    on_click?: RuntimeAction[];
    /** Icon for the per-card action affordance (defaults to a plus). */
    action_icon?: string;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: CardGridBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const { execute } = useActionExecutor();

const appSlug = inject<string>('appSlug', deriveSlugFromUrl());
const pageParams = inject<Record<string, unknown>>('pageParams', {});

function deriveSlugFromUrl(): string {
    const m = window.location.pathname.match(/^\/r\/([a-z][a-z0-9_]*)/);
    return m?.[1] ?? '';
}

const clickable = computed(() => (props.block.on_click?.length ?? 0) > 0);

async function runCardAction(row: RowData) {
    if (!clickable.value) return;
    await execute(props.block.on_click!, { appSlug, row, params: pageParams });
}

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id?: string): FieldDef | undefined {
    return resolveField(object.value, id);
}

const titleField = computed(() => fieldOf(props.block.title_field_id));
const subtitleField = computed(() => fieldOf(props.block.subtitle_field_id));
const imageField = computed(() => fieldOf(props.block.image_field_id));
const metaFields = computed<FieldDef[]>(() =>
    (props.block.meta_fields ?? [])
        .map((m) => fieldOf(m.field_id))
        .filter((f): f is FieldDef => f !== undefined),
);

const gridClass = computed(() => {
    const cols = props.block.columns ?? 3;
    return (
        {
            1: 'grid-cols-1',
            2: 'grid-cols-1 sm:grid-cols-2',
            3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
            4: 'grid-cols-2 lg:grid-cols-4',
            5: 'grid-cols-2 lg:grid-cols-5',
            6: 'grid-cols-2 md:grid-cols-3 lg:grid-cols-6',
        }[cols] ?? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3'
    );
});

const rows = computed(() => props.data?.rows ?? []);

function formatValue(field: FieldDef, value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (field.type === 'currency' && typeof value === 'number') {
        return new Intl.NumberFormat(props.locale, {
            style: 'currency',
            currency: field.currency_code ?? props.defaultCurrency ?? 'MXN',
        }).format(value);
    }
    if (field.type === 'number' && typeof value === 'number') {
        return new Intl.NumberFormat(props.locale).format(value);
    }
    if (field.type === 'single_select') {
        const opt = field.options?.find((o) => o.value === value);
        return opt?.label ?? String(value);
    }
    if (field.type === 'boolean') return value ? '✓' : '—';
    if (field.type === 'date' || field.type === 'datetime') {
        try {
            const d = new Date(String(value));
            return field.type === 'date'
                ? d.toLocaleDateString(props.locale)
                : d.toLocaleString(props.locale);
        } catch {
            return String(value);
        }
    }
    return String(value);
}

function titleOf(row: RowData): string {
    if (!titleField.value) return row.id;
    const v = row.data[titleField.value.slug];
    return v === null || v === undefined || v === '' ? row.id : String(v);
}

function imageSrc(row: RowData): string | null {
    if (!imageField.value) return null;
    const v = row.data[imageField.value.slug];
    return typeof v === 'string' && v !== '' ? v : null;
}
</script>

<template>
    <div :class="['grid gap-3', gridClass]">
        <p
            v-if="rows.length === 0"
            :class="['col-span-full py-8 text-center text-xs', t.textMuted]"
        >
            No records.
        </p>
        <article
            v-for="row in rows"
            :key="row.id"
            :class="[
                'flex flex-col overflow-hidden rounded-sp-sm border transition',
                t.surface,
                clickable
                    ? 'cursor-pointer hover:border-accent-blue hover:shadow-sm'
                    : '',
            ]"
            @click="runCardAction(row)"
        >
            <!-- Image with the action button floated on it (when there is one). -->
            <div v-if="imageSrc(row)" class="relative">
                <img
                    :src="imageSrc(row)!"
                    :alt="titleOf(row)"
                    class="h-32 w-full object-cover"
                    loading="lazy"
                />
                <button
                    v-if="clickable"
                    type="button"
                    aria-label="add"
                    class="absolute right-2 bottom-2 flex h-8 w-8 items-center justify-center rounded-full bg-accent-blue text-white shadow-md hover:bg-accent-blue-hover"
                    @click.stop="runCardAction(row)"
                >
                    <RuntimeIcon :name="block.action_icon ?? 'plus'" :size="16" />
                </button>
            </div>
            <div class="flex flex-1 flex-col gap-1.5 p-4">
                <p :class="['text-sm font-semibold', t.text]">
                    {{ titleOf(row) }}
                </p>
                <p
                    v-if="subtitleField && row.data[subtitleField.slug]"
                    :class="['truncate text-xs', t.textMuted]"
                >
                    {{
                        formatValue(subtitleField, row.data[subtitleField.slug])
                    }}
                </p>
                <dl
                    v-if="metaFields.length"
                    :class="['mt-1 space-y-0.5 text-[11px]', t.textMuted]"
                >
                    <div
                        v-for="f in metaFields"
                        :key="f.id"
                        class="flex items-center justify-between gap-2"
                    >
                        <dt class="truncate">{{ f.name }}</dt>
                        <dd :class="['truncate', t.text]">
                            {{ formatValue(f, row.data[f.slug]) }}
                        </dd>
                    </div>
                </dl>
                <!-- No image: the action button gets its own row so it never
                     overlaps the card content (e.g. the price). -->
                <div
                    v-if="clickable && !imageSrc(row)"
                    class="mt-1 flex justify-end"
                >
                    <button
                        type="button"
                        aria-label="add"
                        class="flex h-8 w-8 items-center justify-center rounded-full bg-accent-blue text-white shadow-sm hover:bg-accent-blue-hover"
                        @click.stop="runCardAction(row)"
                    >
                        <RuntimeIcon
                            :name="block.action_icon ?? 'plus'"
                            :size="16"
                        />
                    </button>
                </div>
            </div>
        </article>
    </div>
</template>
