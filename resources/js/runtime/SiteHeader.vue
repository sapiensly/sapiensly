<script setup lang="ts">
import RuntimeUserMenu from '@/runtime/RuntimeUserMenu.vue';
import { computed } from 'vue';

interface Brand {
    name?: string;
    logo?: string;
    header_bg?: string;
    cta?: { label: string; href: string };
}
interface PageLink {
    slug: string;
    name: string;
}

const props = defineProps<{
    brand?: Brand;
    pages?: PageLink[];
    currentSlug?: string;
    hrefFor?: (slug: string) => string;
}>();

/** Readable text colour (dark/light) for a #RRGGBB background by luminance. */
function readableText(hex: string): string {
    const c = hex.replace('#', '');
    if (c.length !== 6) return '';
    const r = parseInt(c.slice(0, 2), 16);
    const g = parseInt(c.slice(2, 4), 16);
    const b = parseInt(c.slice(4, 6), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6
        ? '#0f172a'
        : '#f8fafc';
}

// When the brand sets a header background (the org "Logo bg color"), paint the
// bar with it and switch the text/borders to a readable contrast; otherwise keep
// the subtle currentColor-tinted default.
const headerStyle = computed(() => {
    const bg = props.brand?.header_bg;
    if (bg) {
        return {
            backgroundColor: bg,
            color: readableText(bg),
            borderColor: 'color-mix(in srgb, currentColor 14%, transparent)',
        };
    }
    return {
        borderColor: 'color-mix(in srgb, currentColor 12%, transparent)',
        backgroundColor: 'color-mix(in srgb, currentColor 4%, transparent)',
    };
});
</script>

<template>
    <header
        class="sticky top-0 z-30 -mx-[var(--sp-bleed,1.25rem)] flex items-center justify-between gap-4 border-b px-[var(--sp-bleed,1.25rem)] py-3.5 backdrop-blur"
        :style="headerStyle"
    >
        <a
            class="flex items-center gap-2 font-semibold"
            :href="hrefFor ? hrefFor(pages?.[0]?.slug ?? '') : '#'"
        >
            <img
                v-if="brand?.logo"
                :src="brand.logo"
                alt=""
                class="h-7 w-auto"
            />
            <span v-if="brand?.name" class="text-base tracking-tight">{{
                brand.name
            }}</span>
        </a>

        <nav
            v-if="pages && pages.length > 1"
            class="hidden items-center gap-1 sm:flex"
        >
            <a
                v-for="p in pages"
                :key="p.slug"
                :href="hrefFor ? hrefFor(p.slug) : '#'"
                class="rounded-pill px-3 py-1.5 text-sm transition-colors"
                :style="{ opacity: p.slug === currentSlug ? 1 : 0.7 }"
            >
                {{ p.name }}
            </a>
        </nav>

        <div class="flex items-center gap-2">
            <a
                v-if="brand?.cta"
                :href="brand.cta.href"
                class="inline-flex items-center rounded-pill px-4 py-2 text-sm font-semibold text-white"
                :style="{ backgroundColor: 'var(--sp-accent, #3b82f6)' }"
            >
                {{ brand.cta.label }}
            </a>

            <!-- Default user widget: identity + "exit to Sapiensly". -->
            <RuntimeUserMenu />
        </div>
    </header>
</template>
