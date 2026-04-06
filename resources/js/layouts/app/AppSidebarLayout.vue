<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import { useLocaleSync } from '@/composables/useLocale';
import type { AppPageProps, BreadcrumbItemType } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

useLocaleSync();

const impersonating = computed(() => usePage<AppPageProps>().props.impersonating);

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <div :class="impersonating && 'is-impersonating'">
        <ImpersonationBanner />
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" class="overflow-x-hidden">
                <AppSidebarHeader :breadcrumbs="breadcrumbs" />
                <slot />
            </AppContent>
        </AppShell>
    </div>
</template>
