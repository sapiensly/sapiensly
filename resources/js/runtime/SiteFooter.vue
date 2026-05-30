<script setup lang="ts">
interface FooterLink { label: string; href: string }

defineProps<{
    footer?: { text?: string; links?: FooterLink[] };
    brandName?: string;
}>();
</script>

<template>
    <footer
        v-if="footer && (footer.text || footer.links?.length || brandName)"
        class="-mx-[var(--sp-bleed,1.25rem)] mt-4 flex flex-col items-center gap-3 border-t px-[var(--sp-bleed,1.25rem)] py-8 text-center"
        :style="{ borderColor: 'color-mix(in srgb, currentColor 12%, transparent)' }"
    >
        <nav v-if="footer.links?.length" class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
            <a v-for="(l, i) in footer.links" :key="i" :href="l.href" class="text-sm" :style="{ opacity: 0.75 }">{{ l.label }}</a>
        </nav>
        <p class="text-xs" :style="{ opacity: 0.6 }">
            {{ footer.text || (brandName ? `© ${brandName}` : '') }}
        </p>
    </footer>
</template>
