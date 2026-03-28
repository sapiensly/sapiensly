<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import type { Document, Folder, GroupedFolders } from '@/types/document';
import {
    ChevronDown,
    ChevronRight,
    File,
    FileText,
    FolderIcon,
    FolderOpen,
    Home,
    Lock,
    Search,
    Users,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Props {
    documents: Document[];
    folders: GroupedFolders;
    excludeDocumentIds?: string[];
}

const props = withDefaults(defineProps<Props>(), {
    excludeDocumentIds: () => [],
});

const emit = defineEmits<{
    select: [documentIds: string[]];
}>();

const open = defineModel<boolean>('open', { required: true });

const searchQuery = ref('');
const selectedFolderId = ref<string | null>(null);
const selectedDocumentIds = ref<Set<string>>(new Set());
const expandedFolders = ref<Set<string>>(new Set());

// Reset state when dialog opens
watch(open, (isOpen) => {
    if (isOpen) {
        searchQuery.value = '';
        selectedFolderId.value = null;
        selectedDocumentIds.value = new Set();
    }
});

const availableDocuments = computed(() => {
    return props.documents.filter(
        (doc) => !props.excludeDocumentIds.includes(doc.id),
    );
});

const filteredDocuments = computed(() => {
    let docs = availableDocuments.value;

    // Filter by folder
    if (selectedFolderId.value !== null) {
        docs = docs.filter((doc) => doc.folder_id === selectedFolderId.value);
    }

    // Filter by search query
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        docs = docs.filter(
            (doc) =>
                doc.name.toLowerCase().includes(query) ||
                doc.original_filename?.toLowerCase().includes(query),
        );
    }

    return docs;
});

const getDocumentIcon = (type: string) => {
    switch (type) {
        case 'pdf':
            return FileText;
        default:
            return File;
    }
};

const toggleDocument = (docId: string) => {
    if (selectedDocumentIds.value.has(docId)) {
        selectedDocumentIds.value.delete(docId);
    } else {
        selectedDocumentIds.value.add(docId);
    }
    // Force reactivity
    selectedDocumentIds.value = new Set(selectedDocumentIds.value);
};

const isDocumentSelected = (docId: string) =>
    selectedDocumentIds.value.has(docId);

const toggleFolder = (folderId: string) => {
    if (expandedFolders.value.has(folderId)) {
        expandedFolders.value.delete(folderId);
    } else {
        expandedFolders.value.add(folderId);
    }
};

const isExpanded = (folderId: string) => expandedFolders.value.has(folderId);
const isActiveFolder = (folderId: string | null) =>
    selectedFolderId.value === folderId;
const hasChildren = (folder: Folder) =>
    folder.children && folder.children.length > 0;

const handleConfirm = () => {
    emit('select', Array.from(selectedDocumentIds.value));
    open.value = false;
};

const typeColors: Record<string, string> = {
    pdf: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    txt: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
    docx: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    md: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    csv: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    json: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex h-[700px] flex-col sm:max-w-5xl">
            <DialogHeader>
                <DialogTitle>Select Documents</DialogTitle>
                <DialogDescription>
                    Select existing documents to add to this knowledge base.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-1 gap-4 overflow-hidden">
                <!-- Folder Sidebar -->
                <div class="w-48 shrink-0 border-r pr-4">
                    <ScrollArea class="h-full">
                        <div class="space-y-2">
                            <!-- All Documents -->
                            <div
                                :class="[
                                    'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                    isActiveFolder(null)
                                        ? 'bg-accent text-accent-foreground'
                                        : '',
                                ]"
                                @click="selectedFolderId = null"
                            >
                                <Home class="h-4 w-4 text-muted-foreground" />
                                <span>All Documents</span>
                            </div>

                            <!-- My Folders -->
                            <div v-if="folders.my.length > 0" class="mt-4">
                                <h4
                                    class="mb-2 px-2 text-xs font-semibold text-muted-foreground uppercase"
                                >
                                    My Folders
                                </h4>
                                <template
                                    v-for="folder in folders.my"
                                    :key="folder.id"
                                >
                                    <div>
                                        <div
                                            :class="[
                                                'group flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                                isActiveFolder(folder.id)
                                                    ? 'bg-accent text-accent-foreground'
                                                    : '',
                                            ]"
                                            @click="
                                                selectedFolderId = folder.id
                                            "
                                        >
                                            <button
                                                v-if="hasChildren(folder)"
                                                type="button"
                                                class="shrink-0 rounded p-0.5 hover:bg-muted"
                                                @click.stop="
                                                    toggleFolder(folder.id)
                                                "
                                            >
                                                <ChevronDown
                                                    v-if="isExpanded(folder.id)"
                                                    class="h-3 w-3"
                                                />
                                                <ChevronRight
                                                    v-else
                                                    class="h-3 w-3"
                                                />
                                            </button>
                                            <span v-else class="w-4" />

                                            <FolderOpen
                                                v-if="
                                                    isExpanded(folder.id) ||
                                                    isActiveFolder(folder.id)
                                                "
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                            <FolderIcon
                                                v-else
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                            <span
                                                class="flex-1 truncate text-xs"
                                                >{{ folder.name }}</span
                                            >
                                        </div>

                                        <!-- Children -->
                                        <div
                                            v-if="
                                                isExpanded(folder.id) &&
                                                hasChildren(folder)
                                            "
                                            class="ml-4"
                                        >
                                            <div
                                                v-for="child in folder.children"
                                                :key="child.id"
                                                :class="[
                                                    'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                                    isActiveFolder(child.id)
                                                        ? 'bg-accent text-accent-foreground'
                                                        : '',
                                                ]"
                                                @click="
                                                    selectedFolderId = child.id
                                                "
                                            >
                                                <span class="w-4" />
                                                <FolderOpen
                                                    v-if="
                                                        isActiveFolder(child.id)
                                                    "
                                                    class="h-4 w-4 text-muted-foreground"
                                                />
                                                <FolderIcon
                                                    v-else
                                                    class="h-4 w-4 text-muted-foreground"
                                                />
                                                <span
                                                    class="flex-1 truncate text-xs"
                                                    >{{ child.name }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Organization Folders -->
                            <div
                                v-if="folders.organization.length > 0"
                                class="mt-4"
                            >
                                <h4
                                    class="mb-2 px-2 text-xs font-semibold text-muted-foreground uppercase"
                                >
                                    Organization
                                </h4>
                                <template
                                    v-for="folder in folders.organization"
                                    :key="folder.id"
                                >
                                    <div
                                        :class="[
                                            'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                            isActiveFolder(folder.id)
                                                ? 'bg-accent text-accent-foreground'
                                                : '',
                                        ]"
                                        @click="selectedFolderId = folder.id"
                                    >
                                        <span class="w-4" />
                                        <FolderOpen
                                            v-if="isActiveFolder(folder.id)"
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <FolderIcon
                                            v-else
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span class="flex-1 truncate text-xs">{{
                                            folder.name
                                        }}</span>
                                        <Users
                                            class="h-3 w-3 text-muted-foreground"
                                        />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </ScrollArea>
                </div>

                <!-- Document List -->
                <div class="flex flex-1 flex-col overflow-hidden">
                    <!-- Search -->
                    <div class="relative mb-4">
                        <Search
                            class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="searchQuery"
                            type="search"
                            placeholder="Search documents..."
                            class="pl-10"
                        />
                    </div>

                    <!-- Documents -->
                    <ScrollArea class="flex-1">
                        <div
                            v-if="filteredDocuments.length === 0"
                            class="flex flex-col items-center justify-center py-12 text-center"
                        >
                            <File
                                class="mb-4 h-12 w-12 text-muted-foreground"
                            />
                            <p class="text-sm text-muted-foreground">
                                {{
                                    availableDocuments.length === 0
                                        ? 'No documents available'
                                        : 'No documents match your search'
                                }}
                            </p>
                        </div>

                        <div v-else class="space-y-2 pr-4">
                            <div
                                v-for="doc in filteredDocuments"
                                :key="doc.id"
                                :class="[
                                    'flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors',
                                    isDocumentSelected(doc.id)
                                        ? 'border-primary bg-primary/5'
                                        : 'hover:bg-muted/50',
                                ]"
                                @click="toggleDocument(doc.id)"
                            >
                                <Checkbox
                                    :checked="isDocumentSelected(doc.id)"
                                    @click.stop="toggleDocument(doc.id)"
                                />
                                <component
                                    :is="getDocumentIcon(doc.type)"
                                    class="h-5 w-5 shrink-0 text-muted-foreground"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">
                                        {{ doc.name }}
                                    </p>
                                    <div
                                        class="flex items-center gap-2 text-xs text-muted-foreground"
                                    >
                                        <span
                                            :class="[
                                                'rounded px-1.5 py-0.5 text-xs',
                                                typeColors[doc.type] ||
                                                    typeColors.txt,
                                            ]"
                                        >
                                            {{ doc.type.toUpperCase() }}
                                        </span>
                                        <span v-if="doc.formatted_file_size">{{
                                            doc.formatted_file_size
                                        }}</span>
                                        <span
                                            v-if="doc.folder"
                                            class="flex items-center gap-1"
                                        >
                                            <FolderIcon class="h-3 w-3" />
                                            {{ doc.folder.name }}
                                        </span>
                                    </div>
                                </div>
                                <component
                                    :is="
                                        doc.visibility === 'organization'
                                            ? Users
                                            : Lock
                                    "
                                    class="h-4 w-4 shrink-0 text-muted-foreground"
                                />
                            </div>
                        </div>
                    </ScrollArea>
                </div>
            </div>

            <DialogFooter class="border-t pt-4">
                <div class="flex-1 text-sm text-muted-foreground">
                    {{ selectedDocumentIds.size }} document(s) selected
                </div>
                <Button variant="outline" @click="open = false">
                    Cancel
                </Button>
                <Button
                    :disabled="selectedDocumentIds.size === 0"
                    @click="handleConfirm"
                >
                    Add Selected
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
