<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import EmptyState from '@/components/agents/EmptyState.vue';
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { PaginatedTools, ToolType, ToolTypeOption } from '@/types/tools';
import { Head, Link, router } from '@inertiajs/vue3';
import { Code, Layers, Plus, Server, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    tools: PaginatedTools;
    toolsByType: Record<ToolType, number>;
    currentType: ToolType | null;
    toolTypes: ToolTypeOption[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('tools.index.heading'), href: '#' },
]);

const toolIcon = (type: ToolType) => {
    switch (type) {
        case 'function':
            return Code;
        case 'mcp':
            return Server;
        case 'group':
            return Layers;
        default:
            return Wrench;
    }
};

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

const filterByType = (type: string | null) => {
    router.get(ToolController.index().url, type ? { type } : {}, {
        preserveState: true,
    });
};

const totalTools = Object.values(props.toolsByType).reduce(
    (sum, count) => sum + count,
    0,
);
</script>

<template>
    <Head :title="t('tools.index.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-6xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        :title="t('tools.index.heading')"
                        :description="t('tools.index.description')"
                    />
                    <Button as-child>
                        <Link :href="ToolController.create().url">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('tools.index.new_tool') }}
                        </Link>
                    </Button>
                </div>

                <Tabs
                    :default-value="currentType ?? 'all'"
                    class="mb-6"
                    @update:model-value="
                        filterByType($event === 'all' ? null : $event)
                    "
                >
                    <TabsList>
                        <TabsTrigger value="all">
                            {{ t('common.all') }} ({{ totalTools }})
                        </TabsTrigger>
                        <TabsTrigger
                            v-for="type in toolTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            <component
                                :is="toolIcon(type.value)"
                                class="mr-2 h-4 w-4"
                            />
                            {{ type.label }} ({{
                                toolsByType[type.value] ?? 0
                            }})
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                <div v-if="tools.data.length === 0">
                    <EmptyState
                        v-if="!currentType"
                        :title="t('tools.index.no_tools')"
                        :description="t('tools.index.no_tools_description')"
                        :create-url="ToolController.create().url"
                        :create-label="t('tools.index.create_tool')"
                    />
                    <EmptyState
                        v-else
                        :title="t('tools.index.no_tools_filtered')"
                        :description="t('tools.index.no_tools_type')"
                        :create-url="`${ToolController.create().url}?type=${currentType}`"
                        :create-label="t('tools.index.create_tool')"
                    />
                </div>

                <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="tool in tools.data"
                        :key="tool.id"
                        :href="ToolController.show({ tool: tool.id }).url"
                    >
                        <Card
                            class="h-full cursor-pointer transition-colors hover:border-primary/50"
                        >
                            <CardHeader>
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-2">
                                        <component
                                            :is="toolIcon(tool.type)"
                                            class="h-5 w-5 text-muted-foreground"
                                        />
                                        <CardTitle class="text-lg">
                                            {{ tool.name }}
                                        </CardTitle>
                                    </div>
                                    <Badge
                                        :variant="statusVariant(tool.status)"
                                    >
                                        {{ tool.status }}
                                    </Badge>
                                </div>
                                <CardDescription v-if="tool.description">
                                    {{ tool.description }}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div
                                    class="flex items-center gap-2 text-sm text-muted-foreground"
                                >
                                    <Badge variant="outline" class="capitalize">
                                        {{ tool.type }}
                                    </Badge>
                                    <span
                                        v-if="tool.is_validated"
                                        class="text-green-600"
                                    >
                                        {{ t('tools.index.validated') }}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
