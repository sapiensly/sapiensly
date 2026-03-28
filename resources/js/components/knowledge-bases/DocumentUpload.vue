<script setup lang="ts">
import * as KnowledgeBaseDocumentController from '@/actions/App/Http/Controllers/KnowledgeBaseDocumentController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { router } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle, FileText, Upload, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    knowledgeBaseId: string;
}

const props = defineProps<Props>();

const isOpen = ref(false);
const isDragging = ref(false);
const files = ref<File[]>([]);
const uploadProgress = ref<
    Map<
        string,
        {
            progress: number;
            status: 'pending' | 'uploading' | 'done' | 'error';
            error?: string;
        }
    >
>(new Map());

const acceptedTypes = [
    'application/pdf',
    'text/plain',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/markdown',
    'text/csv',
    'application/csv',
    'application/json',
];

const acceptedExtensions = '.pdf,.txt,.docx,.md,.csv,.json';

const maxFileSize = 10 * 1024 * 1024; // 10MB

const isUploading = computed(() => {
    return Array.from(uploadProgress.value.values()).some(
        (p) => p.status === 'uploading',
    );
});

const hasErrors = computed(() => {
    return Array.from(uploadProgress.value.values()).some(
        (p) => p.status === 'error',
    );
});

const allDone = computed(() => {
    return (
        files.value.length > 0 &&
        Array.from(uploadProgress.value.values()).every(
            (p) => p.status === 'done' || p.status === 'error',
        )
    );
});

function openDialog() {
    isOpen.value = true;
    files.value = [];
    uploadProgress.value = new Map();
}

function closeDialog() {
    if (isUploading.value) return;
    isOpen.value = false;
    if (allDone.value && !hasErrors.value) {
        router.reload({ only: ['knowledgeBase'] });
    }
}

function handleDragOver(e: DragEvent) {
    e.preventDefault();
    isDragging.value = true;
}

function handleDragLeave(e: DragEvent) {
    e.preventDefault();
    isDragging.value = false;
}

function handleDrop(e: DragEvent) {
    e.preventDefault();
    isDragging.value = false;

    if (e.dataTransfer?.files) {
        addFiles(Array.from(e.dataTransfer.files));
    }
}

function handleFileSelect(e: Event) {
    const target = e.target as HTMLInputElement;
    if (target.files) {
        addFiles(Array.from(target.files));
    }
}

function addFiles(newFiles: File[]) {
    for (const file of newFiles) {
        // Validate file type
        if (!acceptedTypes.includes(file.type)) {
            uploadProgress.value.set(file.name, {
                progress: 0,
                status: 'error',
                error: `Invalid file type: ${file.type || 'unknown'}`,
            });
            continue;
        }

        // Validate file size
        if (file.size > maxFileSize) {
            uploadProgress.value.set(file.name, {
                progress: 0,
                status: 'error',
                error: 'File exceeds 10MB limit',
            });
            continue;
        }

        // Check for duplicates
        if (files.value.some((f) => f.name === file.name)) {
            continue;
        }

        files.value.push(file);
        uploadProgress.value.set(file.name, {
            progress: 0,
            status: 'pending',
        });
    }
}

function removeFile(file: File) {
    files.value = files.value.filter((f) => f.name !== file.name);
    uploadProgress.value.delete(file.name);
}

async function uploadFiles() {
    for (const file of files.value) {
        const progress = uploadProgress.value.get(file.name);
        if (!progress || progress.status !== 'pending') continue;

        uploadProgress.value.set(file.name, {
            progress: 0,
            status: 'uploading',
        });

        try {
            const formData = new FormData();
            formData.append('file', file);

            const url = KnowledgeBaseDocumentController.store({
                knowledge_base: props.knowledgeBaseId,
            }).url;

            // Simulate progress since we can't track real progress with Inertia
            uploadProgress.value.set(file.name, {
                progress: 30,
                status: 'uploading',
            });

            await new Promise<void>((resolve) => {
                router.post(url, formData, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        uploadProgress.value.set(file.name, {
                            progress: 100,
                            status: 'done',
                        });
                        resolve();
                    },
                    onError: (errors) => {
                        uploadProgress.value.set(file.name, {
                            progress: 0,
                            status: 'error',
                            error: Object.values(errors).flat().join(', '),
                        });
                        resolve(); // Don't reject, continue with other files
                    },
                });
            });
        } catch (error) {
            uploadProgress.value.set(file.name, {
                progress: 0,
                status: 'error',
                error: error instanceof Error ? error.message : 'Upload failed',
            });
        }
    }
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}
</script>

<template>
    <div>
        <Button variant="outline" @click="openDialog">
            <Upload class="mr-2 h-4 w-4" />
            Upload Document
        </Button>

        <Dialog :open="isOpen" @update:open="closeDialog">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Upload Documents</DialogTitle>
                    <DialogDescription>
                        Drag and drop files or click to browse. Max 10MB per
                        file.
                    </DialogDescription>
                </DialogHeader>

                <!-- Drop zone -->
                <div
                    class="relative rounded-lg border-2 border-dashed p-8 text-center transition-colors"
                    :class="{
                        'border-primary bg-primary/5': isDragging,
                        'border-muted-foreground/25 hover:border-muted-foreground/50':
                            !isDragging,
                    }"
                    @dragover="handleDragOver"
                    @dragleave="handleDragLeave"
                    @drop="handleDrop"
                >
                    <input
                        type="file"
                        :accept="acceptedExtensions"
                        multiple
                        class="absolute inset-0 cursor-pointer opacity-0"
                        @change="handleFileSelect"
                    />
                    <Upload class="mx-auto h-10 w-10 text-muted-foreground" />
                    <p class="mt-2 text-sm font-medium">
                        Drop files here or click to browse
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        PDF, TXT, DOCX, MD, CSV, JSON
                    </p>
                </div>

                <!-- File list -->
                <div
                    v-if="files.length > 0 || uploadProgress.size > 0"
                    class="mt-4 space-y-2"
                >
                    <div
                        v-for="file in files"
                        :key="file.name"
                        class="flex items-center gap-3 rounded-lg border p-3"
                    >
                        <FileText
                            class="h-5 w-5 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ file.name }}
                            </p>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-muted-foreground">
                                    {{ formatFileSize(file.size) }}
                                </span>
                                <template
                                    v-if="
                                        uploadProgress.get(file.name)
                                            ?.status === 'uploading'
                                    "
                                >
                                    <Progress
                                        :model-value="
                                            uploadProgress.get(file.name)
                                                ?.progress ?? 0
                                        "
                                        class="h-1 flex-1"
                                    />
                                </template>
                                <template
                                    v-else-if="
                                        uploadProgress.get(file.name)
                                            ?.status === 'done'
                                    "
                                >
                                    <CheckCircle
                                        class="h-4 w-4 text-green-500"
                                    />
                                    <span class="text-xs text-green-500"
                                        >Uploaded</span
                                    >
                                </template>
                                <template
                                    v-else-if="
                                        uploadProgress.get(file.name)
                                            ?.status === 'error'
                                    "
                                >
                                    <AlertCircle
                                        class="h-4 w-4 text-destructive"
                                    />
                                    <span class="text-xs text-destructive">
                                        {{
                                            uploadProgress.get(file.name)?.error
                                        }}
                                    </span>
                                </template>
                            </div>
                        </div>
                        <Button
                            v-if="
                                uploadProgress.get(file.name)?.status ===
                                'pending'
                            "
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 shrink-0"
                            @click="removeFile(file)"
                        >
                            <X class="h-4 w-4" />
                        </Button>
                    </div>

                    <!-- Error-only entries (files that failed validation before being added) -->
                    <template
                        v-for="[name, progress] in uploadProgress"
                        :key="name"
                    >
                        <div
                            v-if="
                                progress.status === 'error' &&
                                !files.some((f) => f.name === name)
                            "
                            class="flex items-center gap-3 rounded-lg border border-destructive/50 bg-destructive/5 p-3"
                        >
                            <AlertCircle
                                class="h-5 w-5 shrink-0 text-destructive"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ name }}
                                </p>
                                <p class="text-xs text-destructive">
                                    {{ progress.error }}
                                </p>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Actions -->
                <div class="mt-4 flex justify-end gap-2">
                    <Button
                        variant="outline"
                        :disabled="isUploading"
                        @click="closeDialog"
                    >
                        {{ allDone ? 'Close' : 'Cancel' }}
                    </Button>
                    <Button
                        v-if="!allDone"
                        :disabled="files.length === 0 || isUploading"
                        @click="uploadFiles"
                    >
                        <Upload v-if="!isUploading" class="mr-2 h-4 w-4" />
                        {{
                            isUploading
                                ? 'Uploading...'
                                : `Upload ${files.length} file${files.length !== 1 ? 's' : ''}`
                        }}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
