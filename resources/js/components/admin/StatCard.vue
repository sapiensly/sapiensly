<script setup lang="ts">
import Sparkline from '@/components/admin/Sparkline.vue';
import { TrendingUp } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * Hero stat card for the dashboard top row. Icon tile top-left, delta
 * pill top-right, big value, primary + optional secondary caption below,
 * and a tinted sparkline at the bottom.
 */
interface Props {
    value: string;
    label: string;
    caption?: string;
    delta?: number;
    /** 'up' = good when green, 'down' = good when red (e.g. error rate). */
    deltaDir?: 'up' | 'down';
    series?: number[];
    icon?: Component;
    /** Accent colour for icon tile + sparkline tint. */
    tint?: string;
}

const props = withDefaults(defineProps<Props>(), {
    deltaDir: 'up',
    tint: 'var(--sp-accent-blue)',
});

const deltaPositive = computed(() => {
    if (props.delta === undefined) return true;
    // 'up' means the metric increasing is good. For deltaDir='up' a positive
    // delta is green; for deltaDir='down' a negative delta is green.
    return props.deltaDir === 'up' ? props.delta >= 0 : props.delta <= 0;
});

const deltaColorClass = computed(() =>
    deltaPositive.value ? 'text-sp-success' : 'text-sp-danger',
);

const deltaSign = computed(() =>
    props.delta === undefined ? '' : props.delta > 0 ? '+' : '',
);
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-sp-sm border border-soft bg-navy p-4 transition-all hover:-translate-y-0.5 hover:border-accent-blue/30 hover:shadow-btn-primary"
    >
        <!-- Radial glow corner in the card's tint. Kept light so it reads as
             ambient rather than a second light source competing with the
             sparkline's own glow. -->
        <div
            class="pointer-events-none absolute -top-20 -right-20 h-36 w-36 rounded-full opacity-[0.12] blur-3xl"
            :style="{ backgroundColor: tint }"
            aria-hidden="true"
        />

        <header class="relative flex items-start justify-between gap-2">
            <div
                v-if="icon"
                class="flex size-8 items-center justify-center rounded-xs"
                :style="{
                    backgroundColor: `color-mix(in oklab, ${tint} 15%, transparent)`,
                    color: tint,
                }"
            >
                <component :is="icon" class="size-4" />
            </div>

            <span
                v-if="delta !== undefined"
                :class="[
                    'inline-flex items-center gap-1 text-xs font-semibold',
                    deltaColorClass,
                ]"
            >
                <TrendingUp class="size-3" />
                {{ deltaSign }}{{ delta }}%
            </span>
        </header>

        <div class="relative mt-4 font-mono text-[28px] leading-tight font-semibold text-ink">
            {{ value }}
        </div>

        <p class="relative mt-1 text-xs text-ink-muted">
            {{ label }}
        </p>
        <p v-if="caption" class="relative text-[11px] text-ink-subtle">
            {{ caption }}
        </p>

        <div v-if="series && series.length" class="relative mt-4 h-14">
            <Sparkline :series="series" :tint="tint" />
        </div>
    </div>
</template>
