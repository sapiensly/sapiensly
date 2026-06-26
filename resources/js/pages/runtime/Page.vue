<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppRenderer from '@/runtime/AppRenderer.vue';
import SiteHeader from '@/runtime/SiteHeader.vue';
import SiteFooter from '@/runtime/SiteFooter.vue';
import RuntimeChatPanel from '@/runtime/RuntimeChatPanel.vue';
import { runtimeSettingsStyle } from '@/runtime/runtimeStyle';
import { useScrollReveal } from '@/runtime/useReveal';
import type { RuntimePageProps } from '@/runtime/types/manifest';
import { computed, provide, ref } from 'vue';

const props = defineProps<RuntimePageProps>();

const settings = computed(() => props.manifest.settings ?? {});
const locale = computed(() => settings.value.default_locale ?? 'es-MX');
const defaultCurrency = computed(() => settings.value.default_currency ?? 'MXN');
const theme = computed(() => settings.value.theme ?? 'light');

// Brand defaults to the app name so the site header is never empty.
const brand = computed(() => ({ name: props.app.name, ...(settings.value.brand ?? {}) }));
const footer = computed(() => settings.value.footer);
// Accent colour + font family as CSS vars / inline style on the page surface.
const surfaceStyle = computed(() => ({ '--sp-bleed': '1.25rem', ...runtimeSettingsStyle(settings.value) }));

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

    <!-- Runtime is full-screen (no platform shell), so the app owns the viewport. -->
    <div class="flex min-h-screen flex-col bg-navy-deep" :style="surfaceStyle">
        <!-- Platform bar: always present so the user can leave the app and go back to Sapiensly. -->
        <div class="flex items-center justify-between gap-3 border-b border-soft px-4 py-2">
            <Link
                href="/apps"
                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
            >
                <span aria-hidden="true">←</span> Salir a Sapiensly
            </Link>
            <span class="truncate text-xs text-ink-muted">{{ app.name }}</span>
        </div>

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

        <RuntimeChatPanel
            v-if="manifest.agent?.enabled"
            :app-slug="app.slug"
            :agent-name="manifest.agent.name"
            :theme="theme"
        />
    </div>
</template>
