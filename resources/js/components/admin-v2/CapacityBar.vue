<script setup lang="ts">
import { computed } from 'vue';

/**
 * Thin horizontal capacity bar. Colour tracks % used:
 *   < 80%  → accent-blue (healthy)
 *   80–95% → warning
 *   > 95%  → danger
 *
 * When `used` or `total` is missing we render the bar as empty with a dashed
 * border to signal "data unavailable".
 */
interface Props {
    used: number | null;
    total: number | null;
}

const props = defineProps<Props>();

const pct = computed(() => {
    if (props.used === null || !props.total) return null;
    return Math.min(100, Math.max(0, (props.used / props.total) * 100));
});

const color = computed(() => {
    if (pct.value === null) return 'transparent';
    if (pct.value > 95) return 'var(--sp-danger)';
    if (pct.value > 80) return 'var(--sp-warning)';
    return 'var(--sp-accent-blue)';
});

function bytes(value: number | null): string {
    if (value === null) return '—';
    if (value < 1024) return `${value} B`;
    const units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    let v = value / 1024;
    for (const u of units) {
        if (v < 1024) return `${v.toFixed(1)} ${u}`;
        v /= 1024;
    }
    return `${v.toFixed(1)} EB`;
}
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-baseline justify-between text-xs">
            <span class="text-ink-muted">
                {{ bytes(used) }}
                <span class="text-ink-subtle">/ {{ bytes(total) }}</span>
            </span>
            <span
                v-if="pct !== null"
                class="font-mono"
                :style="{ color }"
            >
                {{ pct.toFixed(1) }}%
            </span>
            <span v-else class="text-ink-subtle">—</span>
        </div>
        <div
            class="h-1.5 overflow-hidden rounded-pill bg-white/5"
            :class="pct === null ? 'border border-dashed border-soft' : ''"
        >
            <div
                v-if="pct !== null"
                class="h-full rounded-pill transition-all"
                :style="{ width: `${pct}%`, backgroundColor: color }"
            />
        </div>
    </div>
</template>
