<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

/**
 * A code somebody can scan off paper.
 *
 * The other direction from the scanner field: reading one is a camera problem,
 * DRAWING one is an encoding problem, and an app that could read barcodes but
 * never print them could not label the thing it was about to be asked to find.
 *
 * One code per row, from the same data pipeline as every other block — so a
 * page filtered to one record prints one label, and the same page unfiltered
 * prints a sheet of them. That is what label stock is: a page of many.
 */
interface BarcodeBlock {
    id: string;
    type: 'barcode';
    label?: string;
    data_source: { object_id: string };
    field_id: string;
    caption_field_id?: string;
    symbology?: 'code128' | 'ean13' | 'qr';
    show_text?: boolean;
    height_px?: number;
    per_row?: number;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: BarcodeBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id: string | undefined): FieldDef | undefined {
    return id ? object.value?.fields.find((f) => f.id === id) : undefined;
}

const symbology = computed(() => props.block.symbology ?? 'code128');

/** {rowId: <svg|dataurl>} — drawn once per value, redrawn when rows change. */
const drawn = ref<Record<string, string>>({});

interface Label {
    id: string;
    value: string;
    caption: string | null;
}

const labels = computed<Label[]>(() => {
    const slug = fieldOf(props.block.field_id)?.slug;
    const captionSlug = fieldOf(props.block.caption_field_id)?.slug;
    if (!slug) return [];

    return (props.data?.rows ?? [])
        .map((r) => {
            const raw = r.data[slug];
            const value = raw === null || raw === undefined ? '' : String(raw);

            return value === ''
                ? null
                : {
                      id: r.id,
                      value,
                      caption: captionSlug
                          ? String(r.data[captionSlug] ?? '')
                          : null,
                  };
        })
        .filter((l): l is Label => l !== null);
});

/**
 * Both encoders are loaded on demand and only the one in use: a page with a
 * QR block has no reason to carry a Code 128 encoder, and most pages carry
 * neither.
 */
async function draw(label: Label): Promise<string | null> {
    try {
        if (symbology.value === 'qr') {
            const QRCode = (await import('qrcode')).default;

            return await QRCode.toDataURL(label.value, {
                margin: 1,
                width: props.block.height_px ?? 96,
                // Black on white, always. A label is read by a machine that
                // does not know what the app's theme is, and prints onto paper
                // that is white whatever the screen was.
                color: { dark: '#000000', light: '#ffffff' },
            });
        }

        const JsBarcode = (await import('jsbarcode')).default;
        const svg = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'svg',
        );

        JsBarcode(svg, label.value, {
            format: symbology.value === 'ean13' ? 'EAN13' : 'CODE128',
            height: props.block.height_px ?? 60,
            displayValue: props.block.show_text !== false,
            margin: 4,
            background: '#ffffff',
            lineColor: '#000000',
            fontSize: 12,
        });

        return svg.outerHTML;
    } catch {
        // An unencodable value — a 12-character EAN, a letter where the
        // symbology allows only digits. Reported in place rather than as a
        // blank space somebody prints a thousand of.
        return null;
    }
}

watch(
    labels,
    async (list) => {
        const next: Record<string, string> = {};
        for (const label of list) {
            const svg = await draw(label);
            if (svg !== null) next[label.id] = svg;
        }
        drawn.value = next;
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div :class="['rounded-sp-sm border p-4', t.surface]">
        <p
            v-if="block.label"
            :class="['mb-3 text-[11px] tracking-wider uppercase', t.textSubtle]"
        >
            {{ block.label }}
        </p>

        <p
            v-if="labels.length === 0"
            :class="['py-6 text-center text-xs', t.textMuted]"
        >
            Nothing to label.
        </p>

        <div
            v-else
            class="grid gap-4"
            :style="{
                gridTemplateColumns: `repeat(${block.per_row ?? 1}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="label in labels"
                :key="label.id"
                :data-sp-barcode="label.value"
                class="flex flex-col items-center gap-1 rounded-xs bg-white p-3"
            >
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div
                    v-if="drawn[label.id] && symbology !== 'qr'"
                    v-html="drawn[label.id]"
                />
                <img
                    v-else-if="drawn[label.id]"
                    :src="drawn[label.id]"
                    :alt="label.value"
                />
                <span v-else class="text-[10px] text-red-600">
                    {{ label.value }} ✕
                </span>

                <span
                    v-if="label.caption"
                    class="max-w-full truncate text-[10px] text-black"
                >
                    {{ label.caption }}
                </span>
            </div>
        </div>
    </div>
</template>
