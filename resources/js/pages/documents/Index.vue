<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import * as FolderController from '@/actions/App/Http/Controllers/FolderController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import DocumentUploadDialog from '@/components/documents/DocumentUploadDialog.vue';
import FolderDialog from '@/components/documents/FolderDialog.vue';
import FolderTree from '@/components/documents/FolderTree.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    DocumentTypeOption,
    Folder,
    GroupedFolders,
    PaginatedDocuments,
    VisibilityOption,
} from '@/types/document';
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import {
    ChevronRight,
    Database,
    File,
    FileText,
    FolderIcon,
    FolderPlus,
    Home,
    Lock,
    Plus,
    Search,
    Trash2,
    Upload,
    Users,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    router.get(
        DocumentController.index({ query: params }).url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}, 300);

watch(searchQuery, () => {
    performSearch();
});

function getDocumentIcon(type: string) {
    switch (type) {
        case 'pdf':
            return FileText;
        default:
            return File;
    }
}

function getVisibilityIcon(visibility: string) {
    return visibility === 'organization' ? Users : Lock;
}

// Type tint map — same pattern as the admin-v2 role / status pills.
const typeTints: Record<string, string> = {
    pdf: 'var(--sp-danger)',
    txt: 'var(--sp-text-secondary)',
    docx: 'var(--sp-accent-blue)',
    md: 'var(--sp-spectrum-magenta)',
    csv: 'var(--sp-success)',
    json: 'var(--sp-warning)',
    artifact: 'var(--sp-accent-cyan)',
};

function tintForType(type: string): string {
    return typeTints[type] ?? 'var(--sp-text-secondary)';
}

function handleDeleteFolder() {
    if (!props.currentFolder) return;

    isDeletingFolder.value = true;

    router.delete(
        FolderController.destroy({ folder: props.currentFolder.id }).url,
        {
            onFinish: () => {
                isDeletingFolder.value = false;
                showDeleteFolderDialog.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('documents.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.documents')">
        <div class="flex gap-6">
            <!-- Folder Tree — scoped to admin-v2 palette. -->
            <aside
                class="hidden w-60 shrink-0 rounded-sp-sm border border-soft bg-navy p-4 lg:block"
            >
                <div class="mb-3 flex items-center justify-between">
                    <h3
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        {{ t('documents.index.folders') }}
                    </h3>
                    <button
                        type="button"
                        class="flex size-7 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
                        @click="showFolderDialog = true"
                    >
                        <FolderPlus class="size-3.5" />
                    </button>
                </div>

                <FolderTree
                    :folders="folderTree"
                    :current-folder-id="currentFolder?.id ?? null"
                />
            </aside>

            <!-- Main content. -->
            <div class="min-w-0 flex-1 space-y-5">
                <PageHeader
                    :title="currentFolder?.name ?? t('app_v2.documents.heading')"
                    :description="t('app_v2.documents.description')"
                >
                    <template #actions>
                        <Link
                            :href="
                                currentFolder?.id
                                    ? `/documents/create?folder=${currentFolder.id}`
                                    : '/documents/create'
                            "
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        >
                            <Plus class="size-3.5" />
                            {{ t('documents.index.create') }}
                        </Link>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                            @click="showUploadDialog = true"
                        >
                            <Upload class="size-3.5" />
                            {{ t('documents.index.upload') }}
                        </button>
                    </template>
                </PageHeader>

                <!-- Breadcrumb trail through folder hierarchy. -->
                <nav
                    v-if="breadcrumbs.length > 0"
                    class="flex items-center gap-1.5 text-xs text-ink-muted"
                >
                    <Link
                        :href="DocumentController.index().url"
                        class="flex items-center gap-1 hover:text-ink"
                    >
                        <Home class="size-3" />
                    </Link>
                    <template
                        v-for="folder in breadcrumbs"
                        :key="folder.id"
                    >
                        <ChevronRight class="size-3 text-ink-subtle" />
                        <Link
                            :href="DocumentController.index({ query: { folder: folder.id } }).url"
                            class="hover:text-ink"
                        >
                            {{ folder.name }}
                        </Link>
                    </template>
                </nav>

                <!-- Search input — pill per admin-v2 pattern. -->
                <div class="relative">
                    <Search
                        class="absolute top-1/2 left-4 size-3.5 -translate-y-1/2 text-ink-subtle"
                    />
                    <Input
                        v-model="searchQuery"
                        type="search"
                        :placeholder="t('app_v2.common.search')"
                        class="h-10 rounded-pill border-medium bg-white/5 pl-10 text-sm text-ink placeholder:text-ink-subtle"
                    />
                </div>

                <!-- Content area — keyed to remount on folder change. -->
                <div :key="currentFolder?.id ?? 'all'">
                    <div
                        v-if="documents.data.length === 0"
                        class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
                    >
                        <div
                            class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                        >
                            <FileText class="size-5" />
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-ink">
                            {{
                                filters.search
                                    ? 'No documents found'
                                    : currentFolder
                                      ? 'Empty folder'
                                      : 'No documents yet'
                            }}
                        </h3>
                        <p class="mt-1 text-xs text-ink-muted">
                            {{
                                filters.search
                                    ? 'Try a different search term.'
                                    : 'Upload your first document to get started.'
                            }}
                        </p>
                        <div class="mt-4 flex items-center justify-center gap-2">
                            <button
                                v-if="!filters.search"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                                @click="showUploadDialog = true"
                            >
                                <Upload class="size-3.5" />
                                {{ t('documents.index.upload') }}
                            </button>
                            <button
                                v-if="currentFolder && canDeleteFolder && !filters.search"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                                @click="showDeleteFolderDialog = true"
                            >
                                <Trash2 class="size-3.5" />
                                Delete folder
                            </button>
                        </div>
                    </div>

                    <div
                        v-else
                        class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
                    >
                        <Link
                            v-for="doc in documents.data"
                            :key="doc.id"
                            :href="DocumentController.show({ document: doc.id }).url"
                            class="flex flex-col rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-xs"
                                        :style="{
                                            backgroundColor: `color-mix(in oklab, ${tintForType(doc.type)} 15%, transparent)`,
                                            color: tintForType(doc.type),
                                        }"
                                    >
                                        <component
                                            :is="getDocumentIcon(doc.type)"
                                            class="size-4"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="line-clamp-1 text-sm font-semibold text-ink">
                                            {{ doc.name }}
                                        </h3>
                                        <p
                                            v-if="doc.original_filename"
                                            class="mt-0.5 line-clamp-1 text-[11px] text-ink-subtle"
                                        >
                                            {{ doc.original_filename }}
                                        </p>
                                    </div>
                                </div>
                                <span
                                    class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                    :style="{
                                        color: tintForType(doc.type),
                                        borderColor: `color-mix(in oklab, ${tintForType(doc.type)} 45%, transparent)`,
                                    }"
                                >
                                    {{ doc.type }}
                                </span>
                            </div>

                            <div
                                class="mt-4 flex flex-wrap items-center gap-3 border-t border-soft pt-3 text-[11px] text-ink-subtle"
                            >
                                <span v-if="doc.formatted_file_size">
                                    {{ doc.formatted_file_size }}
                                </span>
                                <span
                                    v-if="doc.folder && !currentFolder"
                                    class="inline-flex items-center gap-1"
                                >
                                    <FolderIcon class="size-3" />
                                    {{ doc.folder.name }}
                                </span>
                                <span
                                    v-if="doc.knowledge_bases_count"
                                    class="inline-flex items-center gap-1"
                                >
                                    <Database class="size-3" />
                                    {{ doc.knowledge_bases_count }} KB
                                </span>
                                <component
                                    :is="getVisibilityIcon(doc.visibility)"
                                    class="ml-auto size-3.5"
                                    :title="
                                        doc.visibility === 'organization'
                                            ? 'Shared with organization'
                                            : 'Private'
                                    "
                                />
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dialogs reused as-is — out of scope for this plan. -->
        <DocumentUploadDialog
            v-model:open="showUploadDialog"
            :visibility-options="visibilityOptions"
            :can-share-with-org="canShareWithOrg"
            :current-folder-id="currentFolder?.id ?? null"
        />

        <FolderDialog
            v-model:open="showFolderDialog"
            :visibility-options="visibilityOptions"
            :can-share-with-org="canShareWithOrg"
            :parent-folder-id="currentFolder?.id ?? null"
        />

        <Dialog v-model:open="showDeleteFolderDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete Folder</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete "{{ currentFolder?.name }}"?
                        This action cannot be undone.
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
    </AppLayoutV2>
</template>
