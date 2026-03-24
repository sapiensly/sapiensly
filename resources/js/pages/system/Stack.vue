<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Activity, Radio, Server } from 'lucide-vue-next';
import { computed } from 'vue';
import { version } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface ServiceStatus {
    status: 'running' | 'stopped' | 'error';
    version: string;
}

const props = defineProps<{
    stack: {
        php: string;
        laravel: string;
        laravel_ai: string;
        node: string;
        vue: string;
        database: {
            driver: string;
            version: string;
        };
    };
    services: {
        horizon: ServiceStatus;
        reverb: ServiceStatus;
        redis: ServiceStatus;
    };
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    { title: t('system.stack.title'), href: '/system/stack' },
]);

const stackItems = computed(() => [
    { name: t('system.stack.php'), version: props.stack.php, color: 'bg-indigo-500' },
    { name: t('system.stack.laravel'), version: props.stack.laravel, color: 'bg-red-500' },
    { name: t('system.stack.laravel_ai'), version: props.stack.laravel_ai, color: 'bg-violet-500' },
    { name: t('system.stack.nodejs'), version: props.stack.node, color: 'bg-green-500' },
    { name: t('system.stack.vuejs'), version: version, color: 'bg-emerald-500' },
    {
        name: props.stack.database.driver.charAt(0).toUpperCase() + props.stack.database.driver.slice(1),
        version: props.stack.database.version,
        color: 'bg-blue-500',
    },
]);

const serviceItems = computed(() => [
    {
        name: 'Horizon',
        icon: Activity,
        status: props.services.horizon.status,
        version: props.services.horizon.version,
    },
    {
        name: 'Reverb',
        icon: Radio,
        status: props.services.reverb.status,
        version: props.services.reverb.version,
    },
    {
        name: 'Redis',
        icon: Server,
        status: props.services.redis.status,
        version: props.services.redis.version,
    },
]);

const statusVariant = (status: string) => {
    switch (status) {
        case 'running':
            return 'default';
        case 'stopped':
            return 'secondary';
        default:
            return 'destructive';
    }
};

const statusLabel = (status: string) => {
    switch (status) {
        case 'running':
            return t('common.active');
        case 'stopped':
            return t('common.inactive');
        default:
            return 'Error';
    }
};
</script>

<template>
    <Head :title="t('system.stack.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <!-- Stack versions -->
            <div>
                <h3 class="mb-3 text-sm font-medium text-muted-foreground">{{ t('system.stack.title') }}</h3>
                <div class="grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
                    <Card v-for="item in stackItems" :key="item.name">
                        <CardHeader class="pb-2">
                            <div class="flex items-center gap-2">
                                <span
                                    :class="item.color"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white"
                                >
                                    {{ item.name.charAt(0) }}
                                </span>
                                <CardTitle class="truncate text-sm font-medium">
                                    {{ item.name }}
                                </CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p class="truncate text-lg font-semibold tracking-tight sm:text-2xl" :title="item.version">
                                {{ item.version }}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Services status -->
            <div>
                <h3 class="mb-3 text-sm font-medium text-muted-foreground">Services</h3>
                <div class="grid gap-4 grid-cols-1 sm:grid-cols-3">
                    <Card v-for="service in serviceItems" :key="service.name">
                        <CardContent class="flex items-center gap-4 pt-6">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                                <component :is="service.icon" class="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium">{{ service.name }}</p>
                                    <Badge :variant="statusVariant(service.status)" class="text-xs">
                                        <span
                                            v-if="service.status === 'running'"
                                            class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-green-400 animate-pulse"
                                        />
                                        {{ statusLabel(service.status) }}
                                    </Badge>
                                </div>
                                <p class="truncate text-xs text-muted-foreground">
                                    v{{ service.version }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
