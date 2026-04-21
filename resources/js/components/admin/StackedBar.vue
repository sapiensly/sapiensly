<script setup lang="ts">
import { computed } from 'vue';

/**
 * Horizontal stacked bar — each segment is a flex child sized by its value
 * relative to the sum. No SVG needed.
 */
interface Segment {
    label: string;
    value: number;
    tint: string;
}

interface Props {
    segments: Segment[];
    height?: number;
}

const props = withDefaults(defineProps<Props>(), { height: 8 });

const total = computed(() =>
    Math.max(1, props.segments.reduce((acc, s) => acc + s.value, 0)),
);

function widthPct(value: number): string {
    return `${(value / total.value) * 100}%`;
}
</script>

<template>
    <div>
        <div
            class="flex w-full overflow-hidden rounded-pill bg-white/5"
            :style="{ height: `${height}px` }"
            role="img"
            :aria-label="segments.map((s) => `${s.label} ${s.value}`).join(', ')"
        >
            <div
                v-for="s in segments"
                :key="s.label"
                :style="{ width: widthPct(s.value), backgroundColor: s.tint }"
            />
        </div>
        <ul class="mt-2 flex flex-wrap gap-4 text-xs text-ink-muted">
            <li
                v-for="s in segments"
                :key="`sb-legend-${s.label}`"
                class="flex items-center gap-1.5"
            >
                <span
                    class="inline-block size-2 rounded-pill"
                    :style="{ backgroundColor: s.tint }"
                />
                <span>{{ s.label }}</span>
                <span class="font-mono text-ink-subtle">{{ s.value.toLocaleString() }}</span>
            </li>
        </ul>
    </div>
</template>
