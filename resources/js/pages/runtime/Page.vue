<script setup lang="ts">
import AppRenderer from '@/runtime/AppRenderer.vue';
import BlockBreadcrumb from '@/runtime/blocks/BlockBreadcrumb.vue';
import RuntimeChatPanel from '@/runtime/RuntimeChatPanel.vue';
import { runtimeSettingsStyle } from '@/runtime/runtimeStyle';
import SiteFooter from '@/runtime/SiteFooter.vue';
import SiteHeader from '@/runtime/SiteHeader.vue';
import SiteSidebar from '@/runtime/SiteSidebar.vue';
import type { RuntimePageProps } from '@/runtime/types/manifest';
import { useScrollReveal } from '@/runtime/useReveal';
import { useSidebarCollapsed } from '@/runtime/useSidebarCollapsed';
import { Head } from '@inertiajs/vue3';
import { PanelLeftClose, PanelLeftOpen } from '@lucide/vue';
import { computed, provide, ref } from 'vue';

const props = defineProps<RuntimePageProps>();

const settings = computed(() => props.manifest.settings ?? {});
const locale = computed(() => settings.value.default_locale ?? 'es-MX');
const defaultCurrency = computed(
    () => settings.value.default_currency ?? 'MXN',
);
const theme = computed(() => settings.value.theme ?? 'light');

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
    () =>
        (settings.value as { navigation_layout?: string }).navigation_layout ===
        'sidebar',
);
const navItems = computed(
    () =>
        (props.manifest.navigation as { items?: unknown[] } | null)?.items ??
        undefined,
);

// In the sidebar layout the top band hosts the breadcrumb (above the page
// title). If the page authors a breadcrumb block it moves up there; otherwise
// the band falls back to the page name as the title.
const breadcrumbBlock = computed(() => {
    if (!useSidebar.value) {
        return null;
    }
    const blocks = (props.page.blocks ?? []) as Array<Record<string, unknown>>;
    return blocks.find((b) => b.type === 'breadcrumb') ?? null;
});

// Sidebar body: lift the breadcrumb out (it lives in the band). When the band
// shows the page name as title instead, drop a leading heading that repeats it
// so the title never appears twice.
const contentBlocks = computed(() => {
    let blocks = (props.page.blocks ?? []) as Array<Record<string, unknown>>;
    if (!useSidebar.value) {
        return blocks;
    }
    blocks = blocks.filter((b) => b.type !== 'breadcrumb');
    if (
        !breadcrumbBlock.value &&
        blocks[0]?.type === 'heading' &&
        String(blocks[0].content ?? '')
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
// Provide current filter params so a filter_bar block renders pre-filled.
provide('pageParams', props.params ?? {});

// Shared with SiteSidebar — the toggle lives here, at the start of the title bar.
const sidebarCollapsed = useSidebarCollapsed();

const sectionsEl = ref<HTMLElement | null>(null);
useScrollReveal(sectionsEl);
</script>

<template>
    <Head :title="`${app.name} · ${page.name}`" />

    <!-- Runtime is full-screen (no platform shell), so the app owns the viewport.
         The main nav (SiteHeader) carries the user widget, which holds the
         "exit to Sapiensly" action — no separate platform bar. -->
    <!-- Author CSS, pre-scoped to .sp-app-surface server-side (can't leak out).
         Lives at the root so it applies in either layout. -->
    <div class="sp-app-surface" :style="surfaceStyle">
        <component :is="'style'" v-if="customCss" :text-content="customCss" />

        <!-- Sidebar layout: left rail + scrolling content. -->
        <div v-if="useSidebar" class="flex min-h-screen bg-navy-deep">
            <SiteSidebar
                :brand="brand"
                :nav-items="navItems"
                :pages="manifest.pages"
                :current-slug="page.slug"
                :href-for="hrefFor"
            />
            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <!-- Page-title bar, same height as the sidebar header band.
                     The sidebar collapse toggle sits at its start (standard spot). -->
                <header
                    class="flex h-16 shrink-0 items-center gap-2 border-b px-6"
                    :style="{
                        borderColor:
                            'color-mix(in srgb, currentColor 12%, transparent)',
                    }"
                >
                    <button
                        type="button"
                        class="-ml-2 grid size-8 shrink-0 place-items-center rounded-md text-ink-muted transition-colors hover:bg-[color-mix(in_srgb,currentColor_8%,transparent)]"
                        :title="sidebarCollapsed ? 'Expandir menú' : 'Colapsar menú'"
                        @click="sidebarCollapsed = !sidebarCollapsed"
                    >
                        <PanelLeftOpen v-if="sidebarCollapsed" class="size-5" />
                        <PanelLeftClose v-else class="size-5" />
                    </button>
                    <BlockBreadcrumb
                        v-if="breadcrumbBlock"
                        :block="(breadcrumbBlock as any)"
                    />
                    <h1
                        v-else
                        class="truncate text-xl font-semibold tracking-tight"
                    >
                        {{ page.name }}
                    </h1>
                </header>
                <div ref="sectionsEl" class="flex-1 space-y-4 px-6 py-6">
                    <AppRenderer
                        :blocks="contentBlocks"
                        :block-data="blockData"
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
                    :current-slug="page.slug"
                    :href-for="hrefFor"
                />
            </div>

            <div ref="sectionsEl" class="flex-1 space-y-4 px-5 py-6">
                <AppRenderer
                    :blocks="page.blocks"
                    :block-data="blockData"
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
