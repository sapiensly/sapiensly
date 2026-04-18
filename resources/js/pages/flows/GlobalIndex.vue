<script setup lang="ts">
import * as FlowController from '@/actions/App/Http/Controllers/FlowController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Bot, GitBranch, Pencil, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface FlowItem {
    id: string;
    name: string;
    description: string | null;
    status: string;
    version: number;
    updated_at: string;
    agent_id: string;
    agent: {
        id: string;
        name: string;
        type: string;
    } | null;
}

interface Pagination {
    data: FlowItem[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    flows: Pagination;
}

defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.flows'), href: FlowController.globalIndex().url },
]);

const statusVariant = (status: string) => {
    switch (status) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
        default:
            return 'outline';
    }
};

const formatDate = (dateString: string) =>
    new Date(dateString).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <Head :title="t('nav.flows')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-5xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        :title="t('nav.flows')"
                        :description="t('flows.global.description')"
                    />
                    <Button as-child>
                        <Link :href="FlowController.globalCreate().url">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('flows.global.create_flow') }}
                        </Link>
                    </Button>
                </div>

                <div v-if="flows.data.length > 0" class="space-y-3">
                    <Card v-for="flow in flows.data" :key="flow.id">
                        <CardHeader class="pb-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <GitBranch class="h-5 w-5 text-muted-foreground" />
                                    <div>
                                        <CardTitle class="text-base">
                                            {{ flow.name }}
                                        </CardTitle>
                                        <CardDescription v-if="flow.description">
                                            {{ flow.description }}
                                        </CardDescription>
                                    </div>
                                </div>
                                <Badge :variant="statusVariant(flow.status)">
                                    {{ t(`flows.status.${flow.status}`) }}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4 text-xs text-muted-foreground">
                                    <span v-if="flow.agent" class="inline-flex items-center gap-1.5">
                                        <Bot class="h-3.5 w-3.5" />
                                        {{ flow.agent.name }}
                                    </span>
                                    <span>v{{ flow.version }}</span>
                                    <span>{{ formatDate(flow.updated_at) }}</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link
                                            :href="
                                                flow.agent
                                                    ? FlowController.edit({ agent: flow.agent_id, flow: flow.id }).url
                                                    : FlowController.globalEdit({ flow: flow.id }).url
                                            "
                                        >
                                            <Pencil class="mr-1.5 h-3.5 w-3.5" />
                                            {{ t('common.edit') }}
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div
                        v-if="flows.last_page > 1"
                        class="flex items-center justify-between pt-4"
                    >
                        <span class="text-sm text-muted-foreground">
                            {{ t('common.page') }} {{ flows.current_page }} {{ t('common.of') }} {{ flows.last_page }}
                        </span>
                        <div class="flex gap-2">
                            <Link
                                v-if="flows.prev_page_url"
                                :href="flows.prev_page_url"
                                class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                            >
                                {{ t('common.previous') }}
                            </Link>
                            <Link
                                v-if="flows.next_page_url"
                                :href="flows.next_page_url"
                                class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                            >
                                {{ t('common.next') }}
                            </Link>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center justify-center rounded-lg border border-dashed py-12"
                >
                    <GitBranch class="mb-4 h-10 w-10 text-muted-foreground" />
                    <h3 class="mb-1 text-lg font-medium">
                        {{ t('flows.global.no_flows') }}
                    </h3>
                    <p class="mb-4 text-sm text-muted-foreground">
                        {{ t('flows.global.no_flows_description') }}
                    </p>
                    <Button as-child>
                        <Link :href="FlowController.globalCreate().url">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('flows.global.create_flow') }}
                        </Link>
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
