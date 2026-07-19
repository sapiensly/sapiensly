<script setup lang="ts">
import AppRenderer from '@/runtime/AppRenderer.vue';
import BlockBreadcrumb from '@/runtime/blocks/BlockBreadcrumb.vue';
import RuntimeChatPanel from '@/runtime/RuntimeChatPanel.vue';
import { runtimeSettingsStyle } from '@/runtime/runtimeStyle';
import SiteFooter from '@/runtime/SiteFooter.vue';
import SiteHeader from '@/runtime/SiteHeader.vue';
import SiteSidebar from '@/runtime/SiteSidebar.vue';
import type { AnyBlock, RuntimePageProps } from '@/runtime/types/manifest';
import { blockDataBus } from '@/runtime/useActionExecutor';
import { useScrollReveal } from '@/runtime/useReveal';
import { useSidebarCollapsed } from '@/runtime/useSidebarCollapsed';
import { Head } from '@inertiajs/vue3';
import { PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
import { computed, onUnmounted, provide, ref, watch } from 'vue';

const props = defineProps<RuntimePageProps>();

// The shared manifest `settings` type only declares the runtime-relevant subset;
// the authored manifest also carries chrome config (brand/footer/accent/font/
// palette/navigation layout) consumed below. Model that extended shape locally
// so the chrome computeds are typed without touching the shared manifest types.
interface RuntimeSettings {
    default_currency?: string;
    default_locale?: string;
    theme?: 'light' | 'dark';
    brand?: {
        name?: string;
        logo?: string;
        icon?: string;
        header_bg?: string;
        cta?: { label: string; href: string };
    };
    footer?: { text?: string; links?: Array<{ label: string; href: string }> };
    accent?: string;
    font?: string;
    palette?: {
        ramp?: Record<string, string>;
        soft?: string;
        contrast?: string;
        chart?: string[];
    };
    navigation_layout?: string;
    seo?: { title?: string; description?: string; og_image?: string };
}

interface NavItem {
    id: string;
    label: string;
    icon?: string;
    page_id?: string;
    children?: NavItem[];
}

interface PageLink {
    id: string;
    slug: string;
    name: string;
    icon?: string;
    // Record-scoped detail pages ship as `false`; the chrome hides them.
    nav?: boolean;
}

// Live block data: seeded from the server prop, re-synced on every Inertia
// navigation, and patched in place when an action returns fresh data (so adding
// to a cart updates instantly without a second request / full remount).
type BlockDataMap = RuntimePageProps['blockData'];
// blockData is a DEFERRED prop: undefined while the follow-up request runs.
const blockDataPending = computed(() => props.blockData == null);
// Containers/tabs recurse through their own AppRenderer instances — provide
// the pending flag so every depth shows skeletons without prop-drilling.
provide('blockDataLoading', blockDataPending);
const liveBlockData = ref<BlockDataMap>({ ...(props.blockData ?? {}) });
watch(
    () => props.blockData,
    (next) => {
        liveBlockData.value = { ...(next ?? {}) };
    },
);
const stopBlockData = blockDataBus.on((patch) => {
    liveBlockData.value = {
        ...liveBlockData.value,
        ...(patch as BlockDataMap),
    };
});
onUnmounted(stopBlockData);

const settings = computed<RuntimeSettings>(() => props.manifest.settings ?? {});
const locale = computed(() => settings.value.default_locale ?? 'es-MX');
const defaultCurrency = computed(
    () => settings.value.default_currency ?? 'MXN',
);
const theme = computed(() => settings.value.theme ?? 'light');
// Dashboards cap at the standard analytics container width; other app
// kinds keep the full canvas (forms, tables, sites decide their own).
const contentWidthClass = computed(() =>
    props.app.kind === 'dashboard' ? 'mx-auto w-full max-w-[1200px]' : '',
);
// A landing renders chrome-less and full-bleed: no site header/sidebar/footer,
// no content gutters — the page brings its own navbar/footer section blocks and
// each section paints itself edge to edge.
const isLanding = computed(() => props.app.kind === 'landing');
// Head metadata: the public controller ships a resolved top-level `seo` prop;
// the authenticated runtime falls back to the manifest's settings.seo. A
// landing uses the SEO title alone (no "· page" suffix — that's an app idiom).
const seo = computed(
    () =>
        (props as { seo?: RuntimeSettings['seo'] }).seo ??
        settings.value.seo ??
        {},
);
const headTitle = computed(() =>
    isLanding.value && seo.value.title
        ? seo.value.title
        : `${props.app.name} · ${props.page.name}`,
);
// Brand defaults to the app name so the site header is never empty.
const brand = computed(() => ({
    name: props.app.name,
    ...(settings.value.brand ?? {}),
}));
const footer = computed(() => settings.value.footer);
// Accent colour + font family as CSS vars / inline style on the page surface.
const surfaceStyle = computed(() => ({
    '--sp-bleed': '1.25rem',
    ...runtimeSettingsStyle(settings.value),
}));

// Chrome layout: a left sidebar (best for many/nested pages) or the top header.
const useSidebar = computed(
    () => settings.value.navigation_layout === 'sidebar',
);
const navItems = computed<NavItem[] | undefined>(
    () =>
        (props.manifest.navigation?.items as NavItem[] | undefined) ??
        undefined,
);
// SiteSidebar's page links want `icon?: string`, but PageSummary carries
// `icon: string | null`; normalise null → undefined so the prop type matches.
const sidebarPages = computed<PageLink[]>(() =>
    props.manifest.pages.map((p) => ({ ...p, icon: p.icon ?? undefined })),
);

// The menu item to highlight. On a record-scoped detail page the server reports
// the parent list's slug, so drilling into Categorías > Categoría keeps
// "Categorías" active; otherwise it's just the current page.
const activeSlug = computed(() => props.activeSlug ?? props.page.slug);

// In the sidebar layout the top band hosts the breadcrumb (above the page
// title). If the page authors a breadcrumb block it moves up there; otherwise
// the band falls back to the page name as the title.
const breadcrumbBlock = computed<AnyBlock | null>(() => {
    if (!useSidebar.value) {
        return null;
    }
    const blocks = props.page.blocks ?? [];
    return blocks.find((b) => b.type === 'breadcrumb') ?? null;
});

// Sidebar body: the band owns both the breadcrumb and the page title, so lift
// the breadcrumb out and drop a leading heading that just repeats the page name
// (the title never appears twice).
const contentBlocks = computed<AnyBlock[]>(() => {
    let blocks = props.page.blocks ?? [];
    if (!useSidebar.value) {
        return blocks;
    }
    blocks = blocks.filter((b) => b.type !== 'breadcrumb');
    const first = blocks[0];
    if (
        first?.type === 'heading' &&
        String(first.content ?? '')
            .trim()
            .toLowerCase() ===
            String(props.page.name ?? '')
                .trim()
                .toLowerCase()
    ) {
        return blocks.slice(1);
    }
    return blocks;
});

const hrefFor = (slug: string) => `/r/${props.app.slug}/${slug}`;

// Provide the App slug so BlockForm/BlockButton can POST to /r/{slug}/actions.
provide('appSlug', props.app.slug);
// Chart components that resolve CSS-var palettes to hex re-read on this signal
// when the palette changes (see usePaletteSignal).
provide(
    'paletteSignal',
    computed(() => (settings.value as { palette?: unknown }).palette),
);
// Provide current filter params so a filter_bar block renders pre-filled.
provide('pageParams', props.params ?? {});

// Shared with SiteSidebar — the toggle lives here, at the start of the title bar.
const sidebarCollapsed = useSidebarCollapsed();

const sectionsEl = ref<HTMLElement | null>(null);
useScrollReveal(sectionsEl);
</script>

<template>
    <Head :title="headTitle">
        <meta
            v-if="seo.description"
            name="description"
            :content="seo.description"
        />
        <meta property="og:title" :content="headTitle" />
        <meta
            v-if="seo.description"
            property="og:description"
            :content="seo.description"
        />
        <meta v-if="seo.og_image" property="og:image" :content="seo.og_image" />
    </Head>

    <!-- Runtime is full-screen (no platform shell), so the app owns the viewport.
         The main nav (SiteHeader) carries the user widget, which holds the
         "exit to Sapiensly" action — no separate platform bar. -->
    <!-- Author CSS, pre-scoped to .sp-app-surface server-side (can't leak out).
         Lives at the root so it applies in either layout. -->
    <div class="sp-app-surface" :style="surfaceStyle">
        <component :is="'style'" v-if="customCss">{{ customCss }}</component>

        <!-- Landing layout: chrome-less + full-bleed. No SiteHeader/Sidebar/Footer
             — the landing brings its own navbar/footer section blocks and each
             section paints itself edge to edge (no content gutters). -->
        <div v-if="isLanding" ref="sectionsEl" class="min-h-screen">
            <AppRenderer
                :blocks="page.blocks"
                :block-data="liveBlockData"
                :loading="blockDataPending"
                :objects="manifest.objects"
                :locale="locale"
                :default-currency="defaultCurrency"
                :theme="theme"
            />
        </div>

        <!-- Sidebar layout: left rail + scrolling content. -->
        <div v-else-if="useSidebar" class="flex min-h-screen bg-navy-deep">
            <SiteSidebar
                :brand="brand"
                :nav-items="navItems"
                :pages="sidebarPages"
                :current-slug="activeSlug"
                :href-for="hrefFor"
            />
            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <!-- Top header, exactly the height of the sidebar header band
                     (h-16). The collapse toggle sits at the left of both the
                     breadcrumb and the title, which stack compactly within. -->
                <header
                    class="flex h-16 shrink-0 items-center gap-2 border-b px-6"
                    :style="{
                        borderColor:
                            'color-mix(in srgb, currentColor 12%, transparent)',
                    }"
                >
                    <button
                        type="button"
                        class="grid size-8 shrink-0 place-items-center rounded-md text-ink-muted transition-colors hover:bg-[color-mix(in_srgb,currentColor_8%,transparent)]"
                        :title="
                            sidebarCollapsed ? 'Expandir menú' : 'Colapsar menú'
                        "
                        @click="sidebarCollapsed = !sidebarCollapsed"
                    >
                        <PanelLeftOpen v-if="sidebarCollapsed" class="size-5" />
                        <PanelLeftClose v-else class="size-5" />
                    </button>
                    <div
                        v-if="breadcrumbBlock"
                        class="flex min-w-0 flex-col justify-center gap-0.5"
                    >
                        <BlockBreadcrumb :block="breadcrumbBlock as any" />
                        <h1
                            class="truncate text-lg leading-tight font-bold tracking-tight"
                        >
                            {{ page.name }}
                        </h1>
                    </div>
                    <h1
                        v-else
                        class="truncate text-xl font-semibold tracking-tight"
                    >
                        {{ page.name }}
                    </h1>
                </header>
                <div
                    ref="sectionsEl"
                    class="relative flex-1 space-y-4 px-6 py-6"
                    :class="contentWidthClass"
                >
                    <AppRenderer
                        :blocks="contentBlocks"
                        :block-data="liveBlockData"
                        :loading="blockDataPending"
                        :objects="manifest.objects"
                        :locale="locale"
                        :default-currency="defaultCurrency"
                        :theme="theme"
                    />
                </div>
                <div class="px-6">
                    <SiteFooter :footer="footer" :brand-name="brand.name" />
                </div>
            </div>
        </div>

        <!-- Top-header layout (default). -->
        <div v-else class="flex min-h-screen flex-col bg-navy-deep">
            <div class="px-5">
                <SiteHeader
                    :brand="brand"
                    :pages="manifest.pages"
                    :current-slug="activeSlug"
                    :href-for="hrefFor"
                />
            </div>

            <div
                ref="sectionsEl"
                class="relative flex-1 space-y-4 px-5 py-6"
                :class="contentWidthClass"
            >
                <AppRenderer
                    :blocks="page.blocks"
                    :block-data="liveBlockData"
                    :loading="blockDataPending"
                    :objects="manifest.objects"
                    :locale="locale"
                    :default-currency="defaultCurrency"
                    :theme="theme"
                />
            </div>

            <div class="px-5">
                <SiteFooter :footer="footer" :brand-name="brand.name" />
            </div>
        </div>

        <RuntimeChatPanel
            v-if="manifest.agent?.enabled"
            :app-slug="app.slug"
            :agent-name="manifest.agent.name"
            :theme="theme"
        />
    </div>
</template>
