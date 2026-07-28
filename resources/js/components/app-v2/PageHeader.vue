<script setup lang="ts">
/**
 * Shared page header for AppLayoutV2 pages. Renders the 22px non-italic
 * title + muted description, plus an optional `actions` slot for right-
 * aligned buttons (Invite, Create, Export, etc.).
 *
 * Matches the admin-v2 page header recipe — same size, same muted tone.
 *
 * Detail screens whose heading carries more than text — a type icon, a status
 * badge — override the `title` slot rather than rebuilding the header. Three
 * of them used to hand-roll this shape and each one shipped the same
 * off-screen-actions bug on a phone.
 */
interface Props {
    title?: string;
    description?: string | null;
    /**
     * Let the action row wrap onto several lines between `sm` and `lg`.
     *
     * Off by default, and deliberately a choice rather than a rule: a wrapping
     * flex container's min-content is its widest ITEM, not the sum, so the
     * parent hands it less width and a comfortable two-button row breaks onto
     * two lines for no reason. Turn it on for headers carrying four or more
     * actions, where the row genuinely cannot fit a tablet.
     */
    wrapActions?: boolean;
}

withDefaults(defineProps<Props>(), { wrapActions: false });
</script>

<template>
    <!--
      A title plus action buttons does not fit a phone on one line, and actions
      that cannot shrink ran off the right edge — clipped by the shell's
      `overflow-hidden` rather than scrollable, so they were simply unreachable.

      Below `sm` the header stacks. Between `sm` and `lg` the actions wrap,
      which a `shrink-0` here would silently prevent: a row that cannot shrink
      keeps its natural width and never reaches a wrapping point. From `lg` up
      it is the original single row, where buttons compress slightly to fit
      rather than breaking onto a second line.
    -->
    <header
        class="flex flex-col items-start justify-between gap-3 sm:flex-row sm:gap-4"
    >
        <div class="min-w-0 space-y-1">
            <slot name="title">
                <h1 class="text-[22px] leading-tight font-semibold text-ink">
                    {{ title }}
                </h1>
            </slot>
            <p v-if="description" class="text-xs text-ink-muted">
                {{ description }}
            </p>
        </div>

        <div
            v-if="$slots.actions"
            :class="[
                'flex w-full flex-wrap items-center gap-2 sm:w-auto',
                // From `lg` up both modes are the same single row: buttons
                // compress slightly to fit rather than breaking a line.
                wrapActions ? 'lg:flex-nowrap' : 'sm:shrink-0 sm:flex-nowrap',
            ]"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
