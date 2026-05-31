<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        score: number | null;
        reached?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { reached: false, size: 'md' },
);

const pct = computed(() => Math.max(0, Math.min(100, props.score ?? 0)));

// Red → amber → emerald as consensus rises.
const barClass = computed(() => {
    if (props.reached || pct.value >= 80) return 'bg-emerald-500';
    if (pct.value >= 50) return 'bg-amber-500';
    return 'bg-rose-500';
});
</script>

<template>
    <div class="flex items-center gap-2">
        <div
            :class="[
                'relative w-full overflow-hidden rounded-full bg-white/10',
                size === 'sm' ? 'h-1.5' : 'h-2',
            ]"
        >
            <div
                :class="[
                    'h-full rounded-full transition-all duration-700 ease-out',
                    barClass,
                ]"
                :style="{ width: `${pct}%` }"
            />
        </div>
        <span
            :class="[
                'shrink-0 font-semibold tabular-nums',
                size === 'sm' ? 'text-[11px]' : 'text-xs',
                score === null ? 'text-ink-subtle' : 'text-ink',
            ]"
        >
            {{ score === null ? '—' : `${pct}%` }}
        </span>
    </div>
</template>
