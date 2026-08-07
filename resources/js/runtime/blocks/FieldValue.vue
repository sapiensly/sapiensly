<script setup lang="ts">
import { MapPin } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed, ref } from 'vue';
import type { FieldDef } from '../types/manifest';
import { runtimeWord } from '../words';
import {
    EMPTY_MARK,
    formatFieldValue,
    geoPoint,
    isImageFile,
    mapHref,
    storedFile,
    valueChips,
    type DisplayContext,
} from './fieldDisplay';
import ImageLightbox from './ImageLightbox.vue';

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

/**
 * THREE THINGS A VALUE DESERVES BETTER THAN ITS TEXT, decided here so every
 * surface that shows a field gets them at once — the table, the record detail,
 * the related list and the kanban card all render through this component.
 *
 * A read-only field used to print whatever `formatFieldValue` returned, which
 * for these three is a description of the value rather than the value: a pair
 * of coordinates you cannot go to, a filename like
 * «1786121027607827959633554434497113.jpg» for the photo that IS the evidence,
 * and «<p>Nada.</p>» for a note whose content is the word Nada.
 */

/** A point on the earth, and a way to go and see it. */
const point = computed(() =>
    props.field.type === 'geo' ? geoPoint(props.value) : null,
);

/** An uploaded file — a photo, a signature, a document. */
const file = computed(() =>
    props.field.type === 'file' ? storedFile(props.value) : null,
);

const image = computed(() =>
    file.value !== null && isImageFile(file.value) ? file.value : null,
);

const lightboxOpen = ref(false);

/**
 * Markup, rendered.
 *
 * Only where there is room for it: a table cell and a kanban card get one line
 * (`size: 'sm'`), and there the stripped text is the honest answer. A record
 * detail has the whole width.
 *
 * `rich_text` stores HTML — that is what the editor writes. `long_text` is
 * plain by declaration, and goes through markdown because the two things people
 * actually put in a textarea are line breaks and the odd list, and both came
 * out as one run-on paragraph. Marked leaves ordinary prose alone.
 */
const rich = computed<string | null>(() => {
    const type = props.field.type;
    if (props.size === 'sm' || (type !== 'rich_text' && type !== 'long_text')) {
        return null;
    }

    const raw = props.value;
    if (typeof raw !== 'string' || raw.trim() === '') return null;

    const html =
        type === 'rich_text'
            ? raw
            : (marked.parse(raw, { async: false, breaks: true }) as string);

    // The trust boundary. The content is the tenant's own, but it reaches this
    // browser through a record anybody with write access could have filled in —
    // including, on a public portal, a stranger.
    return DOMPurify.sanitize(html);
});

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
    <!-- A picture, shown rather than named. The thumbnail says WHICH photo it
         is; the reader who needs to read the meter on it clicks through. -->
    <span v-if="image" class="inline-flex items-center gap-2">
        <button
            type="button"
            class="block overflow-hidden rounded-sp-sm border border-soft transition-opacity hover:opacity-80"
            :class="size === 'sm' ? 'size-8' : 'size-16'"
            :title="image.name"
            @click.stop="lightboxOpen = true"
        >
            <img
                :src="image.url"
                :alt="image.name"
                class="size-full bg-white object-cover"
            />
        </button>
        <!-- Held on this device and not yet uploaded: the same warning the
             form field shows, because a thumbnail identical to an uploaded
             one has somebody believe the photo is in the record. -->
        <span v-if="image.pending" class="text-[11px] text-amber-500">
            {{ runtimeWord(context.locale, 'offline_file_held') }}
        </span>

        <ImageLightbox
            v-if="lightboxOpen"
            :src="image.url"
            :alt="image.name"
            @close="lightboxOpen = false"
        />
    </span>

    <!-- Any other file: still named, but now something you can open. -->
    <a
        v-else-if="file"
        :href="file.url"
        target="_blank"
        rel="noopener"
        class="truncate underline underline-offset-2"
        @click.stop
        >{{ file.name }}</a
    >

    <!-- A point, and a way to go to it. The coordinates stay: they are what
         gets copied into a report, read out over a radio, or checked against
         another system. -->
    <span v-else-if="point" class="inline-flex flex-wrap items-center gap-x-2">
        <span>{{ text }}</span>
        <a
            :href="mapHref(point)"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 text-accent-blue underline underline-offset-2"
            @click.stop
        >
            <MapPin class="size-3.5 shrink-0" />
            {{ runtimeWord(context.locale, 'geo_view_on_map') }}
        </a>
    </span>

    <!-- Markup, rendered. `sp-rich` is styled once in app.css, so every
         surface showing a note gets the same paragraphs and lists. -->
    <div v-else-if="rich" class="sp-rich" v-html="rich" />

    <span v-else-if="chips" class="inline-flex flex-wrap items-center gap-1">
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
