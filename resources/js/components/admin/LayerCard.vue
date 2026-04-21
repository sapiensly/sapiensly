<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Three-layer tile. Renders one of "Understand" (magenta), "Discover"
 * (cyan), or "Resolve" (indigo). The spectrum colours are reserved for
 * the three-layer brand story — do NOT use them elsewhere in the admin.
 */
interface Props {
    title: string;
    count: number;
    subtitle: string;
    icon: Component;
    /** One of the three spectrum tints. */
    tint: string;
}

defineProps<Props>();
</script>

<template>
    <div
        class="relative overflow-hidden rounded-sp-sm border bg-navy p-5 transition-all hover:-translate-y-0.5"
        :style="{ borderColor: `color-mix(in oklab, ${tint} 35%, transparent)` }"
    >
        <div
            class="pointer-events-none absolute -top-12 -right-12 h-32 w-32 rounded-full opacity-40 blur-2xl"
            :style="{ backgroundColor: tint }"
            aria-hidden="true"
        />

        <div class="relative flex items-center justify-between">
            <div
                class="flex size-8 items-center justify-center rounded-xs"
                :style="{
                    backgroundColor: `color-mix(in oklab, ${tint} 15%, transparent)`,
                    color: tint,
                }"
            >
                <component :is="icon" class="size-4" />
            </div>
            <span
                class="font-mono text-2xl font-semibold"
                :style="{ color: tint }"
            >
                {{ count }}
            </span>
        </div>

        <h3 class="relative mt-3 text-sm font-medium text-ink">{{ title }}</h3>
        <p class="relative mt-0.5 text-xs text-ink-muted">{{ subtitle }}</p>
    </div>
</template>
