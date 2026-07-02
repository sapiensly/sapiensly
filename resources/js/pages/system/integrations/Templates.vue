<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import IntegrationStarterGrid from '@/components/integrations/IntegrationStarterGrid.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('system.integrations.choose.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="space-y-5">
            <PageHeader
                :title="t('system.integrations.choose.title')"
                :description="t('system.integrations.choose.description')"
            >
                <template #actions>
                    <Link href="/system/integrations">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            <ArrowLeft class="size-3.5" />
                            {{ t('system.integrations.templates.back') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <IntegrationStarterGrid />
        </div>
    </AppLayoutV2>
</template>
