<script setup lang="ts">
import RuntimeUserMenu from '@/runtime/RuntimeUserMenu.vue';

interface Brand {
    name?: string;
    logo?: string;
    cta?: { label: string; href: string };
}
interface PageLink {
    slug: string;
    name: string;
}

defineProps<{
    brand?: Brand;
    pages?: PageLink[];
    currentSlug?: string;
    hrefFor?: (slug: string) => string;
}>();
</script>

<template>
    <header
        class="sticky top-0 z-30 -mx-[var(--sp-bleed,1.25rem)] flex items-center justify-between gap-4 border-b px-[var(--sp-bleed,1.25rem)] py-3.5 backdrop-blur"
        :style="{
            borderColor: 'color-mix(in srgb, currentColor 12%, transparent)',
            backgroundColor: 'color-mix(in srgb, currentColor 4%, transparent)',
        }"
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
