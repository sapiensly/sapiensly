<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg
        version="1.1"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 128 128"
        width="170.667"
        height="170.667"
    >
        <path
            fill="#0059ff"
            d="M53.3 10.4c-11.6 2.9-23 10.8-29.1 20.3-13.1 20.5-8 49.9 11 63.9 6.5 4.7 14.6 8.2 21.7 9.1 6.4.8 7.1 1.6 7.1 8.4 0 3.2.5 6.1 1 6.4 1 .6 11.2-7.6 28.2-22.8 15.2-13.5 21.5-28.9 18.8-46.3-4.2-27.7-31.2-45.6-58.7-39z m22.4 15.2c14.2 4.8 23.1 17.9 20.4 30C94 64.9 83.2 74 74.3 74c-2.8 0-3.3.4-3.3 2.5 0 12.2-15.7 12.5-29.3.4C34.9 71 32 64.4 32 55.1c0-8.8 1.9-13.8 7.5-20 6.8-7.5 14.6-10.9 25.2-11 3.4-.1 8.4.6 11 1.5z"
        />
    </svg>
</template>
