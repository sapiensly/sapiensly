<script setup lang="ts">
// The "the model is working" cursor: a small bar that tumbles through eight
// discrete angles (| / — \ …) instead of blinking — it reads as active
// reasoning rather than a paused caret. Inherits the text colour (currentColor)
// and scales to `size`, so it drops in wherever a pulsing caret or spinner was.
withDefaults(defineProps<{ size?: number }>(), { size: 14 });
</script>

<template>
    <span
        class="thinking-cursor"
        :style="{ '--tc-size': size + 'px' }"
        aria-hidden="true"
    >
        <span class="thinking-cursor__bar" />
    </span>
</template>

<style scoped>
.thinking-cursor {
    position: relative;
    display: inline-block;
    width: var(--tc-size);
    height: var(--tc-size);
    line-height: 0;
    vertical-align: middle;
}

.thinking-cursor__bar {
    position: absolute;
    top: 50%;
    left: 50%;
    width: calc(var(--tc-size) * 0.26);
    height: calc(var(--tc-size) * 0.86);
    background: currentColor;
    border-radius: calc(var(--tc-size) * 0.08);
    /* steps(8) snaps the rotation to 45° increments, so the bar visibly tumbles
       | → / → — → \ → … the way the sketch shows, not a smooth spin. */
    animation: thinking-cursor-spin 0.9s steps(8, end) infinite;
    transform: translate(-50%, -50%) rotate(0deg);
    will-change: transform;
}

@keyframes thinking-cursor-spin {
    to {
        transform: translate(-50%, -50%) rotate(360deg);
    }
}

/* Respect reduced-motion: fall back to a gentle fade instead of tumbling. */
@media (prefers-reduced-motion: reduce) {
    .thinking-cursor__bar {
        transform: translate(-50%, -50%);
        animation: thinking-cursor-fade 1.2s ease-in-out infinite;
    }

    @keyframes thinking-cursor-fade {
        50% {
            opacity: 0.35;
        }
    }
}
</style>
