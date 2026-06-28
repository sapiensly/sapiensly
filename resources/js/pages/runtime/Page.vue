<script setup lang="ts">
import AppRenderer from '@/runtime/AppRenderer.vue';
import RuntimeChatPanel from '@/runtime/RuntimeChatPanel.vue';
import { runtimeSettingsStyle } from '@/runtime/runtimeStyle';
import SiteFooter from '@/runtime/SiteFooter.vue';
import SiteHeader from '@/runtime/SiteHeader.vue';
import SiteSidebar from '@/runtime/SiteSidebar.vue';
import type { RuntimePageProps } from '@/runtime/types/manifest';
import { useScrollReveal } from '@/runtime/useReveal';
import { Head } from '@inertiajs/vue3';
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

const hrefFor = (slug: string) => `/r/${props.app.slug}/${slug}`;

// Provide the App slug so BlockForm/BlockButton can POST to /r/{slug}/actions.
provide('appSlug', props.app.slug);
// Provide current filter params so a filter_bar block renders pre-filled.
provide('pageParams', props.params ?? {});

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
