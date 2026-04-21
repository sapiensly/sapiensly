<script setup lang="ts">
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('settings.appearance.breadcrumb')" />

    <SettingsLayout>
        <div class="space-y-3">
            <div class="space-y-1">
                <h2 class="text-sm font-medium text-ink">
                    {{ t('settings.appearance.title') }}
                </h2>
                <p class="text-xs text-ink-muted">
                    {{ t('settings.appearance.description') }}
                </p>
            </div>
            <AppearanceTabs />
        </div>
    </SettingsLayout>
</template>
