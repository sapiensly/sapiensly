<script setup lang="ts">
// slot-only layout
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background p-6"
    >
        <div class="w-full max-w-sm">
            <slot />
        </div>
    </div>
</template>
