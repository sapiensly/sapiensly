<script setup lang="ts">
import StackGroup from '@/components/admin-v2/StackGroup.vue';
import AdminV2Layout from '@/layouts/AdminV2Layout.vue';
import type { StackProps } from '@/lib/admin/types';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps<StackProps>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('admin_v2.nav.stack')" />

    <AdminV2Layout :title="t('admin_v2.nav.stack')">
        <div class="space-y-6">
            <header class="space-y-1">
                <h1 class="text-[22px] font-semibold leading-tight text-ink">
                    {{ t('admin_v2.stack.heading') }}
                </h1>
                <p class="text-xs text-ink-muted">
                    {{ t('admin_v2.stack.description') }}
                </p>
            </header>

            <StackGroup
                v-for="group in groups"
                :key="group.id"
                :label="group.label"
                :items="group.items"
            />
        </div>
    </AdminV2Layout>
</template>
