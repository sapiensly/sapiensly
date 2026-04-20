<script setup lang="ts">
/**
 * Shared page header for AppLayoutV2 pages. Renders the 22px non-italic
 * title + muted description, plus an optional `actions` slot for right-
 * aligned buttons (Invite, Create, Export, etc.).
 *
 * Matches the admin-v2 page header recipe — same size, same muted tone.
 */
interface Props {
    title: string;
    description?: string | null;
}

defineProps<Props>();
</script>

<template>
    <header class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ title }}
            </h1>
            <p v-if="description" class="text-xs text-ink-muted">
                {{ description }}
            </p>
        </div>

        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
