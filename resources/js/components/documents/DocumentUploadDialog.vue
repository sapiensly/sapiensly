<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import KeywordsInput from '@/components/KeywordsInput.vue';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Folder, GroupedFolders, VisibilityOption } from '@/types/document';
import { useForm } from '@inertiajs/vue3';
import { File, FolderIcon, Lock, Upload, Users, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Props {
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
    currentFolderId?: string | null;
    folders?: GroupedFolders;
    showFolderSelector?: boolean;
    knowledgeBaseId?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    currentFolderId: null,
    showFolderSelector: false,
    knowledgeBaseId: null,
});

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    file: null as File | null,
    name: '',
    keywords: [] as string[],
    visibility: 'private',
    folder_id: props.currentFolderId,
    knowledge_base_id: props.knowledgeBaseId,
});

const selectedFile = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const isDragging = ref(false);

watch(
    () => props.currentFolderId,
    (newVal) => {
        form.folder_id = newVal;
    }
);

// Flatten folders for the selector dropdown
const flattenedFolders = computed(() => {
    if (!props.folders) return [];

    const result: { id: string; name: string; depth: number; visibility: string }[] = [];

    const addFolders = (folders: Folder[], depth: number) => {
        for (const folder of folders) {
            result.push({
                id: folder.id,
                name: folder.name,
                depth,
                visibility: folder.visibility,
            });
            if (folder.children?.length) {
                addFolders(folder.children, depth + 1);
            }
        }
    };

    addFolders(props.folders.my, 0);
    addFolders(props.folders.organization, 0);

    return result;
});

const handleFileSelect = (e: Event) => {
    const target = e.target as HTMLInputElement;
    if (target.files?.[0]) {
        selectedFile.value = target.files[0];
        form.file = target.files[0];
        // Set default name from filename
        if (!form.name) {
            form.name = target.files[0].name.replace(/\.[^/.]+$/, '');
        }
    }
};

const handleDrop = (e: DragEvent) => {
    isDragging.value = false;
    if (e.dataTransfer?.files?.[0]) {
        selectedFile.value = e.dataTransfer.files[0];
        form.file = e.dataTransfer.files[0];
        if (!form.name) {
            form.name = e.dataTransfer.files[0].name.replace(/\.[^/.]+$/, '');
        }
    }
};

const removeFile = () => {
    selectedFile.value = null;
    form.file = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const handleSubmit = () => {
    if (!form.file) return;

    form.post(DocumentController.store().url, {
        forceFormData: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
            selectedFile.value = null;
        },
    });
};

const handleClose = () => {
    open.value = false;
    form.reset();
    selectedFile.value = null;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Upload Document</DialogTitle>
                <DialogDescription>
                    Upload a document to your library. Supported formats: PDF, TXT, DOCX, MD, CSV, JSON.
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="handleSubmit" class="space-y-4">
                <!-- File Drop Zone -->
                <div
                    :class="[
                        'relative flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 transition-colors',
                        isDragging
                            ? 'border-primary bg-primary/5'
                            : 'border-muted-foreground/25 hover:border-primary/50',
                    ]"
                    @dragenter.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @dragover.prevent
                    @drop.prevent="handleDrop"
                    @click="fileInput?.click()"
                >
                    <input
                        ref="fileInput"
                        type="file"
                        class="hidden"
                        accept=".pdf,.txt,.docx,.doc,.md,.csv,.json"
                        @change="handleFileSelect"
                    />

                    <template v-if="selectedFile">
                        <div class="flex items-center gap-3">
                            <File class="h-8 w-8 text-muted-foreground" />
                            <div>
                                <p class="text-sm font-medium">{{ selectedFile.name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatFileSize(selectedFile.size) }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="h-6 w-6"
                                @click.stop="removeFile"
                            >
                                <X class="h-4 w-4" />
                            </Button>
                        </div>
                    </template>
                    <template v-else>
                        <Upload class="mb-2 h-8 w-8 text-muted-foreground" />
                        <p class="text-sm text-muted-foreground">
                            Drag and drop or click to upload
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Max 10MB
                        </p>
                    </template>
                </div>

                <!-- Name Input -->
                <div class="space-y-2">
                    <Label for="name">Name (optional)</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="Document name"
                    />
                </div>

                <!-- Keywords Input -->
                <div class="space-y-2">
                    <Label for="keywords">Keywords (optional)</Label>
                    <KeywordsInput v-model="form.keywords" />
                    <p class="text-xs text-muted-foreground">
                        Add keywords to help with search
                    </p>
                </div>

                <!-- Folder Select (when showFolderSelector is true) -->
                <div v-if="showFolderSelector && folders" class="space-y-2">
                    <Label for="folder">Folder (optional)</Label>
                    <Select v-model="form.folder_id">
                        <SelectTrigger>
                            <SelectValue placeholder="No folder (root)" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="null">
                                <span class="text-muted-foreground">No folder (root)</span>
                            </SelectItem>
                            <SelectItem
                                v-for="folder in flattenedFolders"
                                :key="folder.id"
                                :value="folder.id"
                            >
                                <div class="flex items-center gap-2">
                                    <span :style="{ paddingLeft: `${folder.depth * 12}px` }">
                                        <FolderIcon class="inline h-4 w-4 mr-1" />
                                        {{ folder.name }}
                                    </span>
                                    <Users
                                        v-if="folder.visibility === 'organization'"
                                        class="h-3 w-3 text-muted-foreground"
                                    />
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Choose where to save this document
                    </p>
                </div>

                <!-- Visibility Select -->
                <div class="space-y-2">
                    <Label for="visibility">Visibility</Label>
                    <Select v-model="form.visibility" :disabled="!canShareWithOrg">
                        <SelectTrigger>
                            <SelectValue placeholder="Select visibility" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in visibilityOptions"
                                :key="option.value"
                                :value="option.value"
                                :disabled="option.value === 'organization' && !canShareWithOrg"
                            >
                                <div class="flex items-center gap-2">
                                    <component
                                        :is="option.value === 'organization' ? Users : Lock"
                                        class="h-4 w-4"
                                    />
                                    {{ option.label }}
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ visibilityOptions.find(o => o.value === form.visibility)?.description }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="handleClose">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="!form.file || form.processing"
                    >
                        <Upload class="mr-2 h-4 w-4" />
                        Upload
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
