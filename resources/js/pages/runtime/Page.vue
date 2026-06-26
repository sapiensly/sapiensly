<script setup lang="ts">
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head } from '@inertiajs/vue3';
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

    <AppLayoutV2 :title="app.name">
        <div
            :class="[
                'overflow-hidden rounded-sp-sm bg-navy-deep transition-colors',
            ]"
            :style="surfaceStyle"
        >
            <div class="px-5">
                <SiteHeader
                    :brand="brand"
                    :pages="manifest.pages"
                    :current-slug="page.slug"
                    :href-for="hrefFor"
                />
            </div>

            <div ref="sectionsEl" class="space-y-4 px-5 py-6">
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
    </AppLayoutV2>
</template>
