<script setup lang="ts">
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
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
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { PaginatedKnowledgeBases } from '@/types/knowledge-base';
import { Head, Link } from '@inertiajs/vue3';
import { Database, FileText, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    knowledgeBases: PaginatedKnowledgeBases;
}

defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('knowledge_bases.index.heading'), href: '#' },
]);

const statusVariant = (status: string) => {
    switch (status) {
        case 'ready':
            return 'default';
        case 'processing':
            return 'secondary';
        case 'pending':
            return 'outline';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
};
</script>

<template>
    <Head :title="t('knowledge_bases.index.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-6xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        :title="t('knowledge_bases.index.heading')"
                        :description="t('knowledge_bases.index.description')"
                    />
                    <Button as-child>
                        <Link :href="KnowledgeBaseController.create().url">
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('knowledge_bases.index.new_kb') }}
                        </Link>
                    </Button>
                </div>

                <div v-if="knowledgeBases.data.length === 0">
                    <EmptyState
                        :title="t('knowledge_bases.index.no_kbs')"
                        :description="
                            t('knowledge_bases.index.no_kbs_description')
                        "
                        :create-url="KnowledgeBaseController.create().url"
                        :create-label="t('knowledge_bases.index.create_kb')"
                    />
                </div>

                <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="kb in knowledgeBases.data"
                        :key="kb.id"
                        :href="
                            KnowledgeBaseController.show({
                                knowledge_base: kb.id,
                            }).url
                        "
                    >
                        <Card
                            class="h-full cursor-pointer transition-colors hover:border-primary/50"
                        >
                            <CardHeader>
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-2">
                                        <Database
                                            class="h-5 w-5 text-muted-foreground"
                                        />
                                        <CardTitle class="text-lg">
                                            {{ kb.name }}
                                        </CardTitle>
                                    </div>
                                    <Badge :variant="statusVariant(kb.status)">
                                        {{ kb.status }}
                                    </Badge>
                                </div>
                                <CardDescription v-if="kb.description">
                                    {{ kb.description }}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div
                                    class="flex items-center gap-4 text-sm text-muted-foreground"
                                >
                                    <div class="flex items-center gap-1">
                                        <FileText class="h-4 w-4" />
                                        {{ kb.total_documents_count ?? 0 }}
                                        {{
                                            t('knowledge_bases.index.documents')
                                        }}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
