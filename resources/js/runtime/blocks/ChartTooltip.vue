<script setup lang="ts">
import type { ChartTip } from '../useChartTooltip';
import { themeTokens, useRuntimeTheme } from '../useRuntimeTheme';

defineProps<{ tip: ChartTip | null; x: number; y: number }>();

const t = themeTokens(useRuntimeTheme());
</script>

<template>
    <div
        v-if="tip"
        class="pointer-events-none absolute z-20 rounded-sp-sm border border-medium bg-navy-elevated px-2.5 py-1.5 text-[11px] shadow-xl"
        :style="{ left: x + 12 + 'px', top: y + 12 + 'px' }"
    >
        <span class="flex items-center gap-1.5">
            <span
                v-if="tip.color"
                class="size-2 shrink-0 rounded-full"
                :style="{ background: tip.color }"
            />
            <span :class="t.text">{{ tip.title }}</span>
        </span>
        <span
            v-if="tip.value"
            :class="['mt-0.5 block font-semibold tabular-nums', t.text]"
            >{{ tip.value }}</span
        >
    </div>
</template>
