<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('app_v2.nav.dashboard')" />

    <AppLayoutV2 :title="t('app_v2.nav.dashboard')">
        <div class="space-y-6">
            <PageHeader
                :title="t('app_v2.dashboard.heading')"
                :description="t('app_v2.dashboard.description')"
            />

            <!--
              Placeholder welcome panel — real widgets (stats, recent activity,
              live tickets) land in a follow-up plan. Kept intentionally light
              so the shell rolls out without waiting on backend aggregation.
            -->
            <section
                class="rounded-sp-sm border border-soft bg-navy p-8 text-center"
            >
                <h2 class="text-base font-semibold text-ink">
                    {{ t('app_v2.dashboard.welcome_title') }}
                </h2>
                <p
                    class="mx-auto mt-2 max-w-[520px] text-sm text-ink-muted"
                >
                    {{ t('app_v2.dashboard.welcome_body') }}
                </p>
            </section>
        </div>
    </AppLayoutV2>
</template>
