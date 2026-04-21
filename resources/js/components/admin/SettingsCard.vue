<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Shared wrapper used by Access, Cloud, and AI Defaults. Left column: icon
 * tile + title + description + optional badge slot. Right column: body slot
 * with the actual controls. Keeps the visual rhythm consistent across every
 * settings-style screen in admin-v2.
 */
interface Props {
    icon?: Component;
    title: string;
    description?: string;
    /** Tailwind text-color token for the icon tile. Defaults to accent-blue. */
    tint?: string;
}

withDefaults(defineProps<Props>(), { tint: 'var(--sp-accent-blue)' });
</script>

<template>
    <section
        class="grid gap-4 rounded-sp-sm border border-soft bg-navy p-5 md:grid-cols-[220px_1fr]"
    >
        <header class="space-y-2">
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
            <h2 class="text-sm font-medium text-ink">{{ title }}</h2>
            <p v-if="description" class="text-xs text-ink-muted">
                {{ description }}
            </p>
            <slot name="badge" />
        </header>
        <div class="space-y-3">
            <slot />
        </div>
    </section>
</template>
