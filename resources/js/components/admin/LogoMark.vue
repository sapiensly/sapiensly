<script setup lang="ts">
/**
 * Placeholder brand mark. The real SVG from the handoff asset drop (which
 * was not included) should replace the body of this component. Keeps the
 * surface area small so a 1-line swap moves every instance.
 */
withDefaults(
    defineProps<{
        collapsed?: boolean;
    }>(),
    { collapsed: false },
);
</script>

<template>
    <div class="flex items-center gap-2">
        <!-- Mark: a stylised S built from two overlapping accent-blue shapes -->
        <svg
            viewBox="0 0 32 32"
            class="h-6 w-6 shrink-0"
            aria-hidden="true"
            role="img"
        >
            <defs>
                <linearGradient id="sp-logo-grad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="var(--sp-accent-blue)" />
                    <stop offset="100%" stop-color="var(--sp-accent-cyan)" />
                </linearGradient>
            </defs>
            <rect
                x="2"
                y="2"
                width="28"
                height="28"
                rx="8"
                fill="var(--sp-bg-elevated)"
            />
            <path
                d="M10 10 h10 a4 4 0 0 1 0 8 h-6 a4 4 0 0 0 0 8 h10"
                stroke="url(#sp-logo-grad)"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                fill="none"
            />
        </svg>
        <span
            v-if="!collapsed"
            class="font-display text-sm font-semibold tracking-tight text-ink"
        >
            Sapiensly
        </span>
    </div>
</template>
