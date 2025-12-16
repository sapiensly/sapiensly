<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import * as FolderController from '@/actions/App/Http/Controllers/FolderController';
import EmptyState from '@/components/agents/EmptyState.vue';
import DocumentUploadDialog from '@/components/documents/DocumentUploadDialog.vue';
import FolderDialog from '@/components/documents/FolderDialog.vue';
import FolderTree from '@/components/documents/FolderTree.vue';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type {
    DocumentTypeOption,
    Folder,
    GroupedFolders,
    PaginatedDocuments,
    VisibilityOption,
} from '@/types/document';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronRight,
    Database,
    File,
    FileText,
    FolderIcon,
    FolderPlus,
    Home,
    Lock,
    Search,
    Trash2,
    Upload,
    Users,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';

interface Props {
    documents: PaginatedDocuments;
    folderTree: GroupedFolders;
    currentFolder: Folder | null;
    breadcrumbs: Folder[];
    filters: {
        search: string;
    };
    documentTypes: DocumentTypeOption[];
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
    canDeleteFolder: boolean;
}

const props = defineProps<Props>();

const showUploadDialog = ref(false);
const showFolderDialog = ref(false);
const showDeleteFolderDialog = ref(false);
const isDeletingFolder = ref(false);
const searchQuery = ref(props.filters.search);

const performSearch = useDebounceFn(() => {
    const params: Record<string, string> = {};
    if (props.currentFolder?.id) {
        params.folder = props.currentFolder.id;
    }
    if (searchQuery.value) {
        params.search = searchQuery.value;
    }
    router.get(DocumentController.index({ query: params }).url, {}, {
        preserveState: true,
        preserveScroll: true,
    });
}, 300);

watch(searchQuery, () => {
    performSearch();
});

const pageBreadcrumbs = computed<BreadcrumbItem[]>(() => {
    const crumbs: BreadcrumbItem[] = [
        { title: 'Documents', href: DocumentController.index().url },
    ];

    for (const folder of props.breadcrumbs) {
        crumbs.push({
            title: folder.name,
            href: DocumentController.index({ query: { folder: folder.id } }).url,
        });
    }

    return crumbs;
});

const getDocumentIcon = (type: string) => {
    switch (type) {
        case 'pdf':
            return FileText;
        default:
            return File;
    }
};

const getVisibilityIcon = (visibility: string) => {
    return visibility === 'organization' ? Users : Lock;
};

const typeColors: Record<string, string> = {
    pdf: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    txt: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
    docx: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    md: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    csv: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    json: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
};

const handleDeleteFolder = () => {
    if (!props.currentFolder) return;

    isDeletingFolder.value = true;

    router.delete(FolderController.destroy({ folder: props.currentFolder.id }).url, {
        onFinish: () => {
            isDeletingFolder.value = false;
            showDeleteFolderDialog.value = false;
        },
    });
};
</script>

<template>
    <Head title="Documents" />

    <AppLayout :breadcrumbs="pageBreadcrumbs">
        <div class="flex h-full">
            <!-- Sidebar: Folder Tree -->
            <div class="w-64 shrink-0 border-r bg-muted/30 p-4">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">Folders</h3>
                    <Button variant="ghost" size="icon" @click="showFolderDialog = true">
                        <FolderPlus class="h-4 w-4" />
                    </Button>
                </div>

                <FolderTree
                    :folders="folderTree"
                    :current-folder-id="currentFolder?.id ?? null"
                />
            </div>

            <!-- Main Content -->
            <div class="flex-1 px-4 py-6 overflow-auto">
                <div class="mx-auto max-w-6xl">
                    <!-- Header -->
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <Heading
                                :title="currentFolder?.name ?? 'All Documents'"
                                description="Manage your documents and files"
                            />

                            <!-- Breadcrumb navigation -->
                            <nav v-if="breadcrumbs.length > 0" class="mt-2 flex items-center gap-1 text-sm text-muted-foreground">
                                <Link
                                    :href="DocumentController.index().url"
                                    class="flex items-center gap-1 hover:text-foreground"
                                >
                                    <Home class="h-4 w-4" />
                                </Link>
                                <template v-for="folder in breadcrumbs" :key="folder.id">
                                    <ChevronRight class="h-4 w-4" />
                                    <Link
                                        :href="DocumentController.index({ query: { folder: folder.id } }).url"
                                        class="hover:text-foreground"
                                    >
                                        {{ folder.name }}
                                    </Link>
                                </template>
                            </nav>
                        </div>

                        <Button @click="showUploadDialog = true">
                            <Upload class="mr-2 h-4 w-4" />
                            Upload Document
                        </Button>
                    </div>

                    <!-- Search -->
                    <div class="mb-6 relative">
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            v-model="searchQuery"
                            type="search"
                            placeholder="Search documents..."
                            class="pl-10"
                        />
                    </div>

                    <!-- Content area with key to force re-render on folder change -->
                    <div :key="currentFolder?.id ?? 'all'">
                        <!-- Empty State -->
                        <div v-if="documents.data.length === 0">
                            <EmptyState
                                :title="filters.search ? 'No documents found' : (currentFolder ? 'Empty folder' : 'No documents yet')"
                                :description="filters.search
                                    ? 'Try a different search term.'
                                    : (currentFolder
                                        ? 'This folder is empty. Upload a document to get started.'
                                        : 'Upload your first document to get started.')"
                                :create-label="filters.search ? undefined : 'Upload Document'"
                                @create="showUploadDialog = true"
                            >
                                <template v-if="currentFolder && canDeleteFolder && !filters.search" #extra>
                                    <Button
                                        variant="destructive"
                                        @click="showDeleteFolderDialog = true"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        Delete Folder
                                    </Button>
                                </template>
                            </EmptyState>
                        </div>

                        <!-- Documents Grid -->
                        <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="doc in documents.data"
                            :key="doc.id"
                            :href="DocumentController.show({ document: doc.id }).url"
                        >
                            <Card class="h-full cursor-pointer transition-colors hover:border-primary/50">
                                <CardHeader class="pb-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <component
                                                :is="getDocumentIcon(doc.type)"
                                                class="h-5 w-5 shrink-0 text-muted-foreground"
                                            />
                                            <CardTitle class="line-clamp-1 text-base">
                                                {{ doc.name }}
                                            </CardTitle>
                                        </div>
                                        <Badge
                                            :class="typeColors[doc.type] || typeColors.txt"
                                            variant="outline"
                                            class="shrink-0"
                                        >
                                            {{ doc.type.toUpperCase() }}
                                        </Badge>
                                    </div>
                                    <CardDescription v-if="doc.original_filename" class="line-clamp-1 text-xs">
                                        {{ doc.original_filename }}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                        <span v-if="doc.formatted_file_size">
                                            {{ doc.formatted_file_size }}
                                        </span>
                                        <Badge v-if="doc.folder && !currentFolder" variant="secondary" class="text-xs">
                                            <FolderIcon class="mr-1 h-3 w-3" />
                                            {{ doc.folder.name }}
                                        </Badge>
                                        <Badge v-if="doc.knowledge_bases_count" variant="outline" class="text-xs">
                                            <Database class="mr-1 h-3 w-3" />
                                            {{ doc.knowledge_bases_count }} KB
                                        </Badge>
                                        <component
                                            :is="getVisibilityIcon(doc.visibility)"
                                            class="ml-auto h-4 w-4"
                                            :title="doc.visibility === 'organization' ? 'Shared with organization' : 'Private'"
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload Dialog -->
        <DocumentUploadDialog
            v-model:open="showUploadDialog"
            :visibility-options="visibilityOptions"
            :can-share-with-org="canShareWithOrg"
            :current-folder-id="currentFolder?.id ?? null"
        />

        <!-- Folder Dialog -->
        <FolderDialog
            v-model:open="showFolderDialog"
            :visibility-options="visibilityOptions"
            :can-share-with-org="canShareWithOrg"
            :parent-folder-id="currentFolder?.id ?? null"
        />

        <!-- Delete Folder Dialog -->
        <Dialog v-model:open="showDeleteFolderDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete Folder</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete "{{ currentFolder?.name }}"? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button variant="outline" @click="showDeleteFolderDialog = false">
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="isDeletingFolder"
                        @click="handleDeleteFolder"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
