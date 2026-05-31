<script setup lang="ts">
/**
 * Custom "debate" glyph in the lucide line style: two speech bubbles (several
 * voices) with a check inside — a discussion that reaches consensus. Built on
 * lucide's `messages-square` geometry. Accepts the same `class` sizing (size-*)
 * as lucide-vue-next icons.
 */
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <!-- Front (left) bubble -->
        <path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4c0-1.1.9-2 2-2h8a2 2 0 0 1 2 2z" />
        <!-- Back (right) bubble -->
        <path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1" />
        <!-- Consensus check inside the front bubble -->
        <path d="m5.4 6.2 1.7 1.7L10.7 4.3" />
    </svg>
</template>
