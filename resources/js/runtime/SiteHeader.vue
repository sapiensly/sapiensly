<script setup lang="ts">
import NotificationBell from '@/runtime/NotificationBell.vue';
import PortalAuthBar from '@/runtime/PortalAuthBar.vue';
import RuntimeUserMenu from '@/runtime/RuntimeUserMenu.vue';
import { useIsDarkSurface } from '@/runtime/useRuntimeTheme';
import { computed, ref } from 'vue';

interface Brand {
    name?: string;
    logo?: string;
    logo_dark?: string;
    header_bg?: string;
    cta?: { label: string; href: string };
}
interface PageLink {
    slug: string;
    name: string;
    // Record-scoped detail pages ship as `false` and are kept out of the menu.
    nav?: boolean;
}

const props = defineProps<{
    brand?: Brand;
    pages?: PageLink[];
    currentSlug?: string;
    hrefFor?: (slug: string) => string;
    /** Inside the Builder preview the bar scrolls with the board (mirrors
     *  SiteSidebar's `embedded`); only the real runtime pins it. */
    embedded?: boolean;
    /** The slug the app is addressed by, so the bell can fetch its inbox. */
    appSlug?: string;
    /** Portal sign-in state; absent on the authenticated runtime. */
    portalAuth?: {
        enabled: boolean;
        user: { email: string; name: string | null } | null;
    } | null;
    /** Where the portal is mounted, for its auth endpoints. */
    mount?: string;
    /** The app's own locale. The chrome speaks the app's language, not the
     *  platform session's — a Spanish app inside an English session was
     *  labelling its own navigation in English. */
    locale?: string;
}>();

// Only directly-addressable pages belong in the top nav; record-scoped detail
// pages (nav === false) are reached by drilling in, never listed.
const menuPages = computed<PageLink[]>(() =>
    (props.pages ?? []).filter((p) => p.nav !== false),
);

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

// Logo swaps to the dark-surface variant when the header renders on a dark
// background, falling back to the base logo when no variant is set.
const isDark = useIsDarkSurface();
const logoSrc = computed<string | undefined>(
    () =>
        (isDark.value
            ? (props.brand?.logo_dark ?? props.brand?.logo)
            : props.brand?.logo) || undefined,
);

// Active nav item — the same accent-tinted pill the sidebar uses, so the two
// navigation layouts read as one system.
const activeStyle = {
    background: 'var(--sp-accent-soft-2)',
    color: 'var(--sp-accent-text)',
};

/**
 * The narrow-screen menu.
 *
 * The nav was `hidden sm:flex`, so below that width it did not shrink or fold —
 * it vanished, and nothing replaced it. An app with six objects had no way to
 * reach five of them on a phone. The links ARE the app's navigation; they
 * cannot be what gets dropped when the space runs out.
 */
const menuOpen = ref(false);

const MENU_WORD: Record<string, string> = {
    en: 'Menu',
    es: 'Menú',
    pt: 'Menu',
    fr: 'Menu',
};

const menuLabel = computed(
    () =>
        MENU_WORD[(props.locale ?? 'en').slice(0, 2).toLowerCase()] ??
        MENU_WORD.en,
);
</script>

<template>
    <header
        :class="[
            'z-30 -mx-[var(--sp-bleed,1.25rem)] border-b backdrop-blur',
            embedded ? 'relative' : 'sticky top-0',
        ]"
        :style="headerStyle"
    >
        <!-- The bar spans the window; its CONTENTS sit in the same 1360-wide
             column the page content uses, so the brand lands directly above the
             page title instead of drifting to the far edge on a wide screen.
             The gutter is the same --sp-bleed the content column pads by. -->
        <div
            class="mx-auto flex w-full max-w-[1360px] items-center justify-between gap-4 px-[var(--sp-bleed,1.25rem)] py-3.5"
        >
            <!--
                On a phone the logo carries the name.

                «Servicio Campo v11» beside a logo wrapped onto three lines and
                ran under the menu: the brand block had nothing stopping it from
                growing, and the row it shares is the one holding every control.
                The name is the first thing to go, because it is the only thing
                in that row already said by something else.

                Without a logo it stays — then it is the ONLY thing identifying
                the app, and a header with no identity is worse than a long one.
                Truncated either way, so a long name can never do this again.
            -->
            <a
                class="flex min-w-0 items-center gap-2 font-semibold"
                :href="hrefFor ? hrefFor(menuPages[0]?.slug ?? '') : '#'"
            >
                <img
                    v-if="logoSrc"
                    :src="logoSrc"
                    alt=""
                    class="h-7 w-auto shrink-0"
                />
                <span
                    v-if="brand?.name"
                    class="min-w-0 truncate text-base tracking-tight"
                    :class="logoSrc ? 'hidden sm:block' : ''"
                    >{{ brand.name }}</span
                >
            </a>

            <nav
                v-if="menuPages.length > 1"
                class="hidden items-center gap-1 sm:flex"
            >
                <a
                    v-for="p in menuPages"
                    :key="p.slug"
                    :href="hrefFor ? hrefFor(p.slug) : '#'"
                    class="rounded-pill px-3 py-1.5 text-sm transition-colors"
                    :style="
                        p.slug === currentSlug ? activeStyle : { opacity: 0.7 }
                    "
                >
                    {{ p.name }}
                </a>
            </nav>

            <!-- The same destinations where the row does not fit. Not a
                 different navigation — the same one, folded. -->
            <div v-if="menuPages.length > 1" class="relative sm:hidden">
                <button
                    type="button"
                    class="rounded-pill px-3 py-1.5 text-sm opacity-70 transition-opacity hover:opacity-100"
                    :aria-expanded="menuOpen"
                    aria-haspopup="true"
                    data-sp-nav-menu
                    @click="menuOpen = !menuOpen"
                >
                    {{ menuLabel }}
                </button>
                <template v-if="menuOpen">
                    <div class="fixed inset-0 z-20" @click="menuOpen = false" />
                    <div
                        class="absolute left-0 z-30 mt-1 max-h-80 min-w-44 overflow-y-auto rounded-sp-sm border py-1 shadow-lg"
                        :style="{
                            background: 'var(--sp-surface)',
                            borderColor: 'var(--sp-border-medium)',
                        }"
                        role="menu"
                    >
                        <a
                            v-for="p in menuPages"
                            :key="p.slug"
                            :href="hrefFor ? hrefFor(p.slug) : '#'"
                            class="block px-3 py-2 text-sm whitespace-nowrap transition-colors"
                            :style="
                                p.slug === currentSlug
                                    ? activeStyle
                                    : { opacity: 0.75 }
                            "
                        >
                            {{ p.name }}
                        </a>
                    </div>
                </template>
            </div>

            <div class="flex items-center gap-2">
                <a
                    v-if="brand?.cta"
                    :href="brand.cta.href"
                    class="inline-flex items-center rounded-pill px-4 py-2 text-sm font-semibold text-white"
                    :style="{ backgroundColor: 'var(--sp-accent, #3b82f6)' }"
                >
                    {{ brand.cta.label }}
                </a>

                <!-- Portal sign-in, when this portal has identity at all. -->
                <PortalAuthBar
                    v-if="portalAuth?.enabled && mount"
                    :mount="mount"
                    :user="portalAuth.user"
                />

                <!-- In-app inbox. Absent in the builder preview (`embedded`),
                 which has no session to read notifications for, and on a portal,
                 where the viewer is not a platform user. -->
                <NotificationBell
                    v-if="!embedded && appSlug && !portalAuth"
                    :app-slug="appSlug"
                />

                <!-- Default user widget: identity + "exit to Sapiensly". -->
                <RuntimeUserMenu />
            </div>
        </div>
    </header>
</template>
