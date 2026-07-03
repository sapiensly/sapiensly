<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef, ObjectDef } from '../types/manifest';
import { resolveField } from '../types/manifest';
import { useChartTooltip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';
import ChartTooltip from './ChartTooltip.vue';

interface RowData {
    id: string;
    data: Record<string, unknown>;
}
interface WordCloudBlock {
    id: string;
    type: 'word_cloud';
    label?: string;
    data_source: { object_id: string };
    field_id?: string;
    max_words?: number;
}

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    block: WordCloudBlock;
    data: { rows: RowData[] } | undefined;
    objects: ObjectDef[];
    locale: string;
    defaultCurrency: string;
}>();

const t = themeTokens(useRuntimeTheme());
const { card, mouse, tip, onMove, showTip, hideTip } = useChartTooltip();

const field = computed<FieldDef | undefined>(() =>
    resolveField(
        props.objects.find((o) => o.id === props.block.data_source.object_id),
        props.block.field_id,
    ),
);

const palette = [
    '#3B82F6',
    '#10B981',
    '#F59E0B',
    '#EC4899',
    '#8B5CF6',
    '#06B6D4',
];

// Count value frequency, then map each word to a font size by its rank/count.
const words = computed(() => {
    const slug = field.value?.slug;
    if (!slug) return [];
    const counts = new Map<string, number>();
    for (const r of props.data?.rows ?? []) {
        const raw = r.data[slug];
        const vals = Array.isArray(raw) ? raw : [raw];
        for (const v of vals) {
            if (v === null || v === undefined || v === '') continue;
            const key =
                field.value?.type === 'single_select' ||
                field.value?.type === 'multi_select'
                    ? (field.value.options?.find((o) => o.value === v)?.label ??
                      String(v))
                    : String(v);
            counts.set(key, (counts.get(key) ?? 0) + 1);
        }
    }
    const max = Math.max(1, ...counts.values());
    return [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .slice(0, props.block.max_words ?? 40)
        .map(([word, count], i) => ({
            word,
            count,
            size: 0.8 + (count / max) * 1.9, // rem
            color: palette[i % palette.length],
            weight: count / max > 0.6 ? 700 : count / max > 0.3 ? 600 : 500,
        }));
});
</script>

<template>
    <div
        ref="card"
        :class="['relative rounded-sp-sm border p-5', t.surface]"
        @mousemove="onMove"
        @mouseleave="hideTip"
    >
        <ChartTooltip :tip="tip" :x="mouse.x" :y="mouse.y" />
        <p
            v-if="block.label"
            :class="['mb-3 text-[11px] tracking-wider uppercase', t.textSubtle]"
        >
            {{ block.label }}
        </p>
        <p
            v-if="words.length === 0"
            :class="['py-6 text-center text-xs', t.textMuted]"
        >
            No data.
        </p>
        <div
            v-else
            class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1"
        >
            <span
                v-for="w in words"
                :key="w.word"
                class="cursor-pointer leading-tight transition-opacity hover:opacity-70"
                :style="{
                    fontSize: w.size + 'rem',
                    fontWeight: w.weight,
                    color: w.color,
                }"
                @mouseenter="
                    showTip(
                        w.word,
                        w.count + (w.count === 1 ? ' registro' : ' registros'),
                        w.color,
                    )
                "
                >{{ w.word }}</span
            >
        </div>
    </div>
</template>
