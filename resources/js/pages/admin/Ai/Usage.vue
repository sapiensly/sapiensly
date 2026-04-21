<script setup lang="ts">
import AiTabs from '@/components/admin/AiTabs.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps<{
    range: { from: string; to: string } | null;
    totals: { chat: number; embeddings: number; cost: number } | null;
    series: { date: string; chat: number; embeddings: number }[] | null;
    byDriver: { driver: string; calls: number; cost: number }[] | null;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('admin.nav.ai')" />

    <AdminLayout :title="t('admin.nav.ai')">
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] font-semibold leading-tight text-ink">
                    {{ t('admin.ai.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin.ai.usage.description') }}
                </p>
            </header>

            <AiTabs current="usage" />

            <div
                class="rounded-sp-sm border border-soft bg-navy p-8 text-center"
            >
                <p class="text-sm text-ink">
                    {{ t('admin.ai.usage.empty_title') }}
                </p>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ t('admin.ai.usage.empty_body') }}
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
