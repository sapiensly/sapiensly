<script setup lang="ts">
interface Props {
    title: string;
    description?: string;
}

defineProps<Props>();
</script>

<template>
    <header>
        <div class="mb-0.5 flex items-center gap-2">
            <slot name="icon" />
            <h3 class="text-base font-medium">{{ title }}</h3>
        </div>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
