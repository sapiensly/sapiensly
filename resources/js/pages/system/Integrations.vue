<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Plug } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    { title: t('system.integrations.title'), href: '/system/integrations' },
]);
</script>

<template>
    <Head :title="t('system.integrations.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-5xl">
                <div class="mb-8">
                    <Heading
                        :title="t('system.integrations.title')"
                        :description="t('system.integrations.description')"
                    />
                </div>

                <Card>
                    <CardContent class="flex flex-col items-center justify-center gap-4 py-16 text-center">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                            <Plug class="h-8 w-8 text-muted-foreground" />
                        </div>
                        <div class="space-y-1">
                            <h3 class="text-lg font-semibold">
                                {{ t('system.integrations.coming_soon') }}
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                {{ t('system.integrations.coming_soon_description') }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
