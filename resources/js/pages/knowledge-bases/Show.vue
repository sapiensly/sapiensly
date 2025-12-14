<script setup lang="ts">
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { DocumentTypeOption, KnowledgeBase } from '@/types/knowledge-base';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Pencil, Trash2, Upload } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    knowledgeBase: KnowledgeBase;
    documentTypes: DocumentTypeOption[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Knowledge Base', href: KnowledgeBaseController.index().url },
    { title: props.knowledgeBase.name, href: '#' },
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

const deleteKnowledgeBase = () => {
    router.delete(
        KnowledgeBaseController.destroy({ knowledge_base: props.knowledgeBase.id }).url,
    );
};

const documentTypeLabel = (type: string) => {
    const found = props.documentTypes.find((dt) => dt.value === type);
    return found?.label ?? type.toUpperCase();
};
</script>

<template>
    <Head :title="knowledgeBase.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <Heading :title="knowledgeBase.name" />
                            <Badge :variant="statusVariant(knowledgeBase.status)">
                                {{ knowledgeBase.status }}
                            </Badge>
                        </div>
                        <p
                            v-if="knowledgeBase.description"
                            class="text-muted-foreground"
                        >
                            {{ knowledgeBase.description }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    KnowledgeBaseController.edit({
                                        knowledge_base: knowledgeBase.id,
                                    }).url
                                "
                            >
                                <Pencil class="mr-2 h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                        <Dialog>
                            <DialogTrigger as-child>
                                <Button variant="destructive">
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    Delete
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Delete Knowledge Base</DialogTitle>
                                    <DialogDescription>
                                        Are you sure you want to delete "{{
                                            knowledgeBase.name
                                        }}"? This will also delete all documents and cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="outline">Cancel</Button>
                                    </DialogClose>
                                    <Button
                                        variant="destructive"
                                        @click="deleteKnowledgeBase"
                                    >
                                        Delete
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <div class="space-y-8">
                    <Card>
                        <CardHeader>
                            <CardTitle>Configuration</CardTitle>
                            <CardDescription>
                                Processing settings for this knowledge base
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">
                                        Chunk Size
                                    </dt>
                                    <dd class="mt-1">
                                        {{ knowledgeBase.config?.chunk_size ?? 1000 }} characters
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">
                                        Chunk Overlap
                                    </dt>
                                    <dd class="mt-1">
                                        {{ knowledgeBase.config?.chunk_overlap ?? 200 }} characters
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">
                                        Documents
                                    </dt>
                                    <dd class="mt-1">{{ knowledgeBase.document_count }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">
                                        Chunks
                                    </dt>
                                    <dd class="mt-1">{{ knowledgeBase.chunk_count }}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <div>
                        <div class="flex items-center justify-between">
                            <HeadingSmall
                                title="Documents"
                                description="Files and URLs in this knowledge base"
                            />
                            <Button variant="outline" disabled>
                                <Upload class="mr-2 h-4 w-4" />
                                Upload Document
                            </Button>
                        </div>

                        <div class="mt-4">
                            <div
                                v-if="!knowledgeBase.documents || knowledgeBase.documents.length === 0"
                                class="rounded-lg border border-dashed p-8 text-center"
                            >
                                <FileText class="mx-auto h-8 w-8 text-muted-foreground" />
                                <p class="mt-2 text-sm text-muted-foreground">
                                    No documents yet. Upload documents to populate this knowledge base.
                                </p>
                            </div>

                            <div v-else class="space-y-3">
                                <Card
                                    v-for="doc in knowledgeBase.documents"
                                    :key="doc.id"
                                >
                                    <CardContent class="flex items-center justify-between py-4">
                                        <div class="flex items-center gap-3">
                                            <FileText class="h-5 w-5 text-muted-foreground" />
                                            <div>
                                                <p class="font-medium">
                                                    {{ doc.original_filename ?? doc.source }}
                                                </p>
                                                <p class="text-sm text-muted-foreground">
                                                    {{ documentTypeLabel(doc.type) }}
                                                </p>
                                            </div>
                                        </div>
                                        <Badge :variant="statusVariant(doc.embedding_status)">
                                            {{ doc.embedding_status }}
                                        </Badge>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
