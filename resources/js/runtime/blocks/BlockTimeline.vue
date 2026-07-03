<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

interface TimelineBlock {
    id: string;
    type: 'timeline';
    label?: string;
    data_source: { object_id: string };
    date_field_id: string;
    title_field_id: string;
    description_field_id?: string;
    color_field_id?: string;
}

interface RowData {
    id: string;
    data: Record<string, unknown>;
}

const props = defineProps<{
    block: TimelineBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());

const object = computed<ObjectDef | undefined>(() =>
    props.objects.find((o) => o.id === props.block.data_source.object_id),
);

function fieldOf(id?: string): FieldDef | undefined {
    return resolveField(object.value, id);
}

const dateField = computed(() => fieldOf(props.block.date_field_id));
const titleField = computed(() => fieldOf(props.block.title_field_id));
const descField = computed(() => fieldOf(props.block.description_field_id));
const colorField = computed(() => fieldOf(props.block.color_field_id));

interface Entry {
    id: string;
    date: Date | null;
    dateLabel: string;
    title: string;
    description: string | null;
    color: string;
}

const entries = computed<Entry[]>(() => {
    const rows = props.data?.rows ?? [];
    const dSlug = dateField.value?.slug;
    const tSlug = titleField.value?.slug;
    const sSlug = descField.value?.slug;
    const cSlug = colorField.value?.slug;

    return rows
        .map<Entry | null>((r) => {
            const rawDate = dSlug ? r.data[dSlug] : null;
            const d = rawDate ? new Date(String(rawDate)) : null;
            const valid = d && !isNaN(d.getTime());
            let color = '#3B82F6';
            if (colorField.value?.type === 'single_select' && cSlug) {
                const opt = colorField.value.options?.find(
                    (o) => o.value === r.data[cSlug],
                );
                if (opt?.color) color = opt.color;
            }
            return {
                id: r.id,
                date: valid ? d : null,
                dateLabel: valid
                    ? d!.toLocaleString(props.locale, {
                          dateStyle: 'medium',
                          timeStyle: 'short',
                      })
                    : '—',
                title: tSlug ? String(r.data[tSlug] ?? r.id) : r.id,
                description: sSlug
                    ? r.data[sSlug]
                        ? String(r.data[sSlug])
                        : null
                    : null,
                color,
            };
        })
        .filter((e): e is Entry => e !== null)
        .sort((a, b) => (b.date?.getTime() ?? 0) - (a.date?.getTime() ?? 0));
});
</script>

<template>
    <div :class="['rounded-sp-sm border p-5', t.surface]">
        <header v-if="block.label" class="mb-4">
            <p :class="['text-[11px] tracking-wider uppercase', t.textSubtle]">
                {{ block.label }}
            </p>
        </header>
        <p
            v-if="entries.length === 0"
            :class="['text-center text-xs', t.textMuted]"
        >
            No entries.
        </p>
        <ol v-else class="relative ml-3 space-y-4 border-l border-soft pl-5">
            <li
                v-for="e in entries"
                :key="e.id"
                class="relative rounded-xs py-1 pr-2 transition-colors hover:bg-surface"
            >
                <span
                    class="absolute top-2 -left-[26px] size-2.5 rounded-pill border-2"
                    :style="{
                        background: e.color,
                        borderColor: 'var(--sp-navy, #0b1530)',
                    }"
                />
                <p :class="['text-[11px]', t.textSubtle]">{{ e.dateLabel }}</p>
                <p :class="['mt-0.5 text-sm font-medium', t.text]">
                    {{ e.title }}
                </p>
                <p
                    v-if="e.description"
                    :class="['mt-0.5 text-xs', t.textMuted]"
                >
                    {{ e.description }}
                </p>
            </li>
        </ol>
    </div>
</template>
