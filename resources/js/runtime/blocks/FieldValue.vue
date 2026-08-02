<script setup lang="ts">
import { computed } from 'vue';
import type { FieldDef } from '../types/manifest';
import {
    EMPTY_MARK,
    formatFieldValue,
    valueChips,
    type DisplayContext,
} from './fieldDisplay';

/**
 * One field's value, drawn the way that field deserves.
 *
 * A select carries a colour per option and only the kanban ever used it: the
 * same "Urgente" read as a coloured dot on a card and as flat grey text one
 * block below, in the table. Here it is a chip everywhere, tinted with the
 * option's own colour.
 *
 * The tint is built with color-mix against `--sp-text-primary`, which is near
 * black on a light surface and white on a dark one. So the same hex reads as a
 * deep ink on pale wash in light mode and a bright tone on a dim wash in dark
 * mode, without this component knowing which theme it is in — an app may even
 * declare its own (.theme-light on a dark platform), and mixing in CSS follows
 * whatever tokens are actually in scope rather than a boolean read once at
 * mount.
 */
const props = withDefaults(
    defineProps<{
        field: FieldDef;
        value: unknown;
        context: DisplayContext;
        /** `sm` for dense surfaces (table rows, kanban cards). */
        size?: 'sm' | 'md';
    }>(),
    { size: 'md' },
);

const chips = computed(() => valueChips(props.field, props.value));
const text = computed(() =>
    formatFieldValue(props.field, props.value, props.context),
);

/**
 * Absence, drawn as absence.
 *
 * A missing value already prints an em dash rather than vanishing — hiding it
 * would leave the reader unable to tell an empty field from one the object does
 * not have. But the dash was rendered in the same ink as a real value, so a
 * column of them read like content. It is a mark meaning "nothing here", and it
 * says so by receding.
 */
const isBlank = computed(
    () => chips.value === null && text.value === EMPTY_MARK,
);

/**
 * A chip's three surfaces from one hue. Falls back to the neutral token chip
 * when the option carries no colour, so an uncoloured select still reads as a
 * chip and not as loose text.
 */
function chipStyle(color?: string): Record<string, string> {
    if (!color) return {};
    return {
        backgroundColor: `color-mix(in srgb, ${color} 16%, transparent)`,
        borderColor: `color-mix(in srgb, ${color} 34%, transparent)`,
        color: `color-mix(in srgb, ${color} 74%, var(--sp-text-primary))`,
    };
}

const chipClass = computed(() => [
    'inline-flex max-w-full items-center gap-1.5 truncate rounded-pill border',
    props.size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-0.5 text-xs',
    'border-soft bg-surface text-ink-muted',
]);
</script>

<template>
    <span v-if="chips" class="inline-flex flex-wrap items-center gap-1">
        <span
            v-for="(chip, i) in chips"
            :key="i"
            :class="chipClass"
            :style="chipStyle(chip.color)"
            :title="chip.label"
        >
            <span
                v-if="chip.color"
                class="size-1.5 shrink-0 rounded-full"
                :style="{ backgroundColor: chip.color }"
            />
            <span class="truncate">{{ chip.label }}</span>
        </span>
    </span>
    <span v-else :class="isBlank ? 'text-ink-subtle' : ''">{{ text }}</span>
</template>
