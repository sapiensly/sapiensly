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
    <!--
      A title plus two action buttons does not fit a phone on one line, and the
      actions being `shrink-0` meant they ran off the right edge — clipped by
      the shell's `overflow-hidden` rather than scrollable, so they were simply
      unreachable. Below `sm` the header stacks and the actions wrap; from `sm`
      up it is the original single row.
    -->
    <header
        class="flex flex-col items-start justify-between gap-3 sm:flex-row sm:gap-4"
    >
        <div class="min-w-0 space-y-1">
            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                {{ title }}
            </h1>
            <p v-if="description" class="text-xs text-ink-muted">
                {{ description }}
            </p>
        </div>

        <div
            v-if="$slots.actions"
            class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0 sm:flex-nowrap"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
