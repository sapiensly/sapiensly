<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon: Component;
        tint?: string;
    }>(),
    { tint: 'var(--sp-accent-blue)' },
);
</script>

<template>
    <div class="rounded-sp-sm border border-soft bg-navy">
        <div class="flex items-center gap-3 border-b border-soft px-5 py-4">
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-xs"
                :style="{
                    backgroundColor: `color-mix(in oklab, ${tint} 15%, transparent)`,
                    color: tint,
                }"
            >
                <component :is="icon" class="size-4" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-ink">{{ title }}</p>
                <p v-if="description" class="text-xs text-ink-muted">
                    {{ description }}
                </p>
            </div>
            <div class="shrink-0">
                <slot name="actions" />
            </div>
        </div>
        <div class="px-5 py-4">
            <slot />
        </div>
    </div>
</template>
