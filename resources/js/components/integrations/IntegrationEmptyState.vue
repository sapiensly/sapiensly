<script setup lang="ts">
import IntegrationStarterGrid from '@/components/integrations/IntegrationStarterGrid.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div class="space-y-5">
        <div class="space-y-1">
            <h3 class="text-sm font-semibold text-ink">
                {{ t('system.integrations.starter.title') }}
            </h3>
            <p class="max-w-xl text-xs text-ink-muted">
                {{ t('system.integrations.starter.description') }}
            </p>
        </div>

        <IntegrationStarterGrid />
    </div>
</template>
