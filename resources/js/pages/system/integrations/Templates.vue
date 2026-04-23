<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { INTEGRATION_TEMPLATES } from '@/lib/integrations/templates';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const templates = INTEGRATION_TEMPLATES;
</script>

<template>
    <Head :title="t('system.integrations.templates.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="space-y-5">
            <PageHeader
                :title="t('system.integrations.templates.title')"
                :description="t('system.integrations.templates.description')"
            >
                <template #actions>
                    <Link href="/system/integrations">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            <ArrowLeft class="size-3.5" />
                            {{ t('system.integrations.templates.back') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                <Link
                    v-for="template in templates"
                    :key="template.slug"
                    :href="`/system/integrations/create?template=${template.slug}`"
                    class="flex cursor-pointer flex-col items-start gap-2 rounded-sp-sm border border-soft bg-white/[0.03] p-5 text-left transition-colors hover:border-accent-blue/30 hover:bg-white/[0.06]"
                >
                    <div
                        class="flex size-9 items-center justify-center rounded-xs"
                        :style="{
                            backgroundColor: `color-mix(in oklab, ${template.tint} 15%, transparent)`,
                            color: template.tint,
                        }"
                    >
                        <component :is="template.icon" class="size-4" />
                    </div>
                    <h3 class="text-sm font-semibold text-ink">
                        {{ template.label }}
                    </h3>
                    <p class="text-xs text-ink-muted">
                        {{ t(template.descriptionKey) }}
                    </p>
                </Link>
            </div>
        </div>
    </AppLayoutV2>
</template>
