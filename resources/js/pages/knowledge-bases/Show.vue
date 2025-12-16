<script setup lang="ts">
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import * as KnowledgeBaseDocumentController from '@/actions/App/Http/Controllers/KnowledgeBaseDocumentController';
import DocumentSelectorDialog from '@/components/documents/DocumentSelectorDialog.vue';
import DocumentUploadDialog from '@/components/documents/DocumentUploadDialog.vue';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Document, GroupedFolders, VisibilityOption } from '@/types/document';
import type { DocumentTypeOption, KnowledgeBase, KnowledgeBaseDocument } from '@/types/knowledge-base';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, FolderPlus, MoreVertical, Pencil, Plus, RefreshCw, Trash2, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    knowledgeBase: KnowledgeBase;
    documentTypes: DocumentTypeOption[];
    availableDocuments: Document[];
    attachedDocumentIds: string[];
    folders: GroupedFolders;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
}

const props = defineProps<Props>();

const showDocumentSelector = ref(false);
const showUploadDialog = ref(false);

// Combine legacy documents and new attached documents
const allDocuments = computed(() => {
    const legacy = props.knowledgeBase.documents || [];
    const attached = props.knowledgeBase.attached_documents || [];

    // Map attached documents to have similar structure as legacy
    const mappedAttached = attached.map(doc => ({
        id: doc.id,
        original_filename: doc.original_filename,
        name: doc.name,
        source: doc.name,
        type: doc.type,
        embedding_status: doc.pivot?.embedding_status || 'pending',
        error_message: doc.pivot?.error_message || null,
        isAttached: true, // Flag to differentiate
    }));

    // Map legacy documents
    const mappedLegacy = legacy.map(doc => ({
        ...doc,
        isAttached: false,
    }));

    return [...mappedLegacy, ...mappedAttached];
});

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

const deleteDocument = (doc: KnowledgeBaseDocument & { isAttached?: boolean }) => {
    if (!confirm(`Remove "${doc.original_filename ?? doc.name ?? doc.source}"?`)) return;

    if (doc.isAttached) {
        // Detach new Document model
        router.delete(
            KnowledgeBaseController.detachDocument({
                knowledge_base: props.knowledgeBase.id,
                document: doc.id,
            }).url,
            { preserveScroll: true },
        );
    } else {
        // Delete legacy KnowledgeBaseDocument
        router.delete(
            KnowledgeBaseDocumentController.destroy({
                knowledge_base: props.knowledgeBase.id,
                document: doc.id,
            }).url,
            { preserveScroll: true },
        );
    }
};

const reprocessDocument = (doc: KnowledgeBaseDocument) => {
    router.post(
        KnowledgeBaseDocumentController.reprocess({
            knowledge_base: props.knowledgeBase.id,
            document: doc.id,
        }).url,
        {},
        { preserveScroll: true },
    );
};

const handleDocumentsSelected = (documentIds: string[]) => {
    if (documentIds.length === 0) return;

    router.post(
        KnowledgeBaseController.attachDocuments({
            knowledge_base: props.knowledgeBase.id,
        }).url,
        { document_ids: documentIds },
        { preserveScroll: true },
    );
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
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button>
                                        <Plus class="mr-2 h-4 w-4" />
                                        Add Document
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @click="showDocumentSelector = true">
                                        <FolderPlus class="mr-2 h-4 w-4" />
                                        Add Existing Document
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem @click="showUploadDialog = true">
                                        <Upload class="mr-2 h-4 w-4" />
                                        Upload New Document
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

                        <div class="mt-4">
                            <div
                                v-if="allDocuments.length === 0"
                                class="rounded-lg border border-dashed p-8 text-center"
                            >
                                <FileText class="mx-auto h-8 w-8 text-muted-foreground" />
                                <p class="mt-2 text-sm text-muted-foreground">
                                    No documents yet. Upload documents to populate this knowledge base.
                                </p>
                            </div>

                            <div v-else class="space-y-3">
                                <Card
                                    v-for="doc in allDocuments"
                                    :key="doc.id"
                                >
                                    <CardContent class="flex items-center justify-between py-4">
                                        <div class="flex items-center gap-3">
                                            <FileText class="h-5 w-5 text-muted-foreground" />
                                            <div>
                                                <p class="font-medium">
                                                    {{ doc.original_filename ?? doc.source }}
                                                </p>
                                                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <span>{{ documentTypeLabel(doc.type) }}</span>
                                                    <span v-if="doc.error_message" class="text-destructive">
                                                        - {{ doc.error_message }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Badge :variant="statusVariant(doc.embedding_status)">
                                                {{ doc.embedding_status }}
                                            </Badge>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <Button variant="ghost" size="icon" class="h-8 w-8">
                                                        <MoreVertical class="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        v-if="doc.embedding_status === 'failed'"
                                                        @click="reprocessDocument(doc)"
                                                    >
                                                        <RefreshCw class="mr-2 h-4 w-4" />
                                                        Retry Processing
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        class="text-destructive focus:text-destructive"
                                                        @click="deleteDocument(doc)"
                                                    >
                                                        <Trash2 class="mr-2 h-4 w-4" />
                                                        Delete
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Selector Dialog -->
        <DocumentSelectorDialog
            v-model:open="showDocumentSelector"
            :documents="availableDocuments"
            :folders="folders"
            :exclude-document-ids="attachedDocumentIds"
            @select="handleDocumentsSelected"
        />

        <!-- Upload Dialog -->
        <DocumentUploadDialog
            v-model:open="showUploadDialog"
            :visibility-options="visibilityOptions"
            :can-share-with-org="canShareWithOrg"
            :folders="folders"
            :show-folder-selector="true"
            :knowledge-base-id="knowledgeBase.id"
        />
    </AppLayout>
</template>
