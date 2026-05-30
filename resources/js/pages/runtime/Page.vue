<script setup lang="ts">
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppRenderer from '@/runtime/AppRenderer.vue';
import type { RuntimePageProps } from '@/runtime/types/manifest';
import { computed, provide } from 'vue';

const props = defineProps<RuntimePageProps>();

const locale = computed(() => props.manifest.settings.default_locale ?? 'es-MX');
const defaultCurrency = computed(() => props.manifest.settings.default_currency ?? 'MXN');
const theme = computed(() => props.manifest.settings.theme ?? 'light');

// Provide the App slug so BlockForm/BlockButton can POST to /r/{slug}/actions
// without parsing window.location themselves.
provide('appSlug', props.app.slug);
</script>

<template>
    <Head :title="`${app.name} · ${page.name}`" />

    <AppLayoutV2 :title="app.name">
        <div class="space-y-6">
            <header class="flex flex-col gap-3 border-b border-soft pb-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-[11px] uppercase tracking-wider text-ink-subtle">
                        {{ app.name }}
                    </p>
                    <h1 class="text-[22px] font-semibold leading-tight text-ink">
                        {{ page.name }}
                    </h1>
                </div>

                <nav v-if="manifest.pages.length > 1" class="flex flex-wrap gap-1.5">
                    <Link
                        v-for="p in manifest.pages"
                        :key="p.id"
                        :href="`/r/${app.slug}/${p.slug}`"
                        :class="[
                            'inline-flex items-center rounded-pill border px-3 py-1 text-xs transition-colors',
                            p.slug === page.slug
                                ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                                : 'border-medium bg-white/5 text-ink-muted hover:border-strong hover:text-ink',
                        ]"
                    >
                        {{ p.name }}
                    </Link>
                </nav>
            </header>

            <div
                :class="[
                    'space-y-4 overflow-hidden rounded-sp-sm p-5 transition-colors',
                    theme === 'light' ? 'bg-white' : 'bg-slate-950',
                ]"
                :style="{ '--sp-bleed': '1.25rem' }"
            >
                <AppRenderer
                    :blocks="page.blocks"
                    :block-data="blockData"
                    :objects="manifest.objects"
                    :locale="locale"
                    :default-currency="defaultCurrency"
                    :theme="theme"
                />
            </div>
        </div>
    </AppLayoutV2>
</template>
