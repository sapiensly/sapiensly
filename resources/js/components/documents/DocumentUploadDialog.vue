<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import KeywordsInput from '@/components/KeywordsInput.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import type {
    Folder,
    GroupedFolders,
    VisibilityOption,
} from '@/types/document';
import { useForm } from '@inertiajs/vue3';
import {
    Code2,
    File as FileIcon,
    FileText,
    FolderIcon,
    Image,
    Loader2,
    Lock,
    Upload,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

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

const { t } = useI18n();

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
    },
);

const flattenedFolders = computed(() => {
    if (!props.folders) return [];

    const result: {
        id: string;
        name: string;
        depth: number;
        visibility: string;
    }[] = [];

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

// Pick an icon + tint for the selected file so HTML artifacts read as
// different from plain docs at a glance.
const fileKind = computed(() => {
    if (!selectedFile.value) return { icon: FileIcon, tint: 'var(--sp-text-secondary)' };
    const ext = selectedFile.value.name.split('.').pop()?.toLowerCase() ?? '';
    if (ext === 'html' || ext === 'htm') {
        return { icon: Code2, tint: 'var(--sp-accent-cyan)' };
    }
    if (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'].includes(ext)) {
        return { icon: Image, tint: 'var(--sp-spectrum-magenta)' };
    }
    return { icon: FileText, tint: 'var(--sp-accent-blue)' };
});

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
        <!--
            sp-admin-dialog rewires the shadcn CSS variables inside the Dialog
            portal to admin-v2 tones (navy surface, text-ink hierarchy, soft
            borders), so Input/Select/Label/Label slots pick up the right
            colors without per-slot overrides.
        -->
        <DialogContent class="sp-admin-dialog sm:max-w-lg">
            <DialogHeader>
                <div class="flex items-start gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/15 text-accent-blue"
                    >
                        <Upload class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <DialogTitle class="text-base font-semibold text-ink">
                            {{ t('documents.upload.title') }}
                        </DialogTitle>
                        <DialogDescription class="mt-1 text-xs text-ink-muted">
                            {{ t('documents.upload.description') }}
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="handleSubmit">
                <!-- File drop zone -->
                <div>
                    <div
                        :class="[
                            'relative flex min-h-32 cursor-pointer flex-col items-center justify-center gap-2 rounded-sp-sm border-2 border-dashed p-5 transition-colors',
                            isDragging
                                ? 'border-accent-blue bg-accent-blue/5'
                                : 'border-soft bg-white/[0.02] hover:border-medium hover:bg-white/[0.04]',
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
                            accept=".pdf,.txt,.docx,.doc,.md,.csv,.json,.html,.htm"
                            @change="handleFileSelect"
                        />

                        <template v-if="selectedFile">
                            <div class="flex w-full items-center gap-3">
                                <div
                                    class="flex size-9 shrink-0 items-center justify-center rounded-xs"
                                    :style="{
                                        backgroundColor: `color-mix(in oklab, ${fileKind.tint} 15%, transparent)`,
                                        color: fileKind.tint,
                                    }"
                                >
                                    <component :is="fileKind.icon" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1 text-left">
                                    <p class="truncate text-sm font-medium text-ink">
                                        {{ selectedFile.name }}
                                    </p>
                                    <p class="text-xs text-ink-subtle">
                                        {{ formatFileSize(selectedFile.size) }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-xs text-ink-muted transition-colors hover:bg-sp-danger/10 hover:text-sp-danger"
                                    @click.stop="removeFile"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>
                        </template>
                        <template v-else>
                            <Upload class="size-7 text-ink-subtle" />
                            <p class="text-sm text-ink">
                                {{ t('documents.upload.dropzone_hint') }}
                            </p>
                            <p class="text-xs text-ink-subtle">
                                {{ t('documents.upload.max_size') }}
                            </p>
                        </template>
                    </div>
                    <p class="mt-2 text-[11px] text-ink-subtle">
                        {{ t('documents.upload.supported_formats') }}
                    </p>
                </div>

                <!-- Name -->
                <div class="space-y-1.5">
                    <Label for="name">
                        {{ t('documents.upload.name_label') }}
                    </Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        :placeholder="t('documents.upload.name_placeholder')"
                        class="h-9"
                    />
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('documents.upload.name_hint') }}
                    </p>
                </div>

                <!-- Keywords -->
                <div class="space-y-1.5">
                    <Label for="keywords">
                        {{ t('documents.upload.keywords_label') }}
                    </Label>
                    <KeywordsInput v-model="form.keywords" />
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('documents.upload.keywords_hint') }}
                    </p>
                </div>

                <!-- Folder -->
                <div v-if="showFolderSelector && folders" class="space-y-1.5">
                    <Label for="folder">
                        {{ t('documents.upload.folder_label') }}
                    </Label>
                    <Select v-model="form.folder_id">
                        <SelectTrigger class="h-9">
                            <SelectValue :placeholder="t('documents.upload.folder_none')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="null">
                                <span class="text-ink-muted">
                                    {{ t('documents.upload.folder_none') }}
                                </span>
                            </SelectItem>
                            <SelectItem
                                v-for="folder in flattenedFolders"
                                :key="folder.id"
                                :value="folder.id"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        :style="{
                                            paddingLeft: `${folder.depth * 12}px`,
                                        }"
                                    >
                                        <FolderIcon class="mr-1 inline size-4" />
                                        {{ folder.name }}
                                    </span>
                                    <Users
                                        v-if="folder.visibility === 'organization'"
                                        class="size-3 text-ink-subtle"
                                    />
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-[11px] text-ink-subtle">
                        {{ t('documents.upload.folder_hint') }}
                    </p>
                </div>

                <!-- Visibility -->
                <div class="space-y-1.5">
                    <Label for="visibility">
                        {{ t('documents.upload.visibility_label') }}
                    </Label>
                    <Select v-model="form.visibility" :disabled="!canShareWithOrg">
                        <SelectTrigger class="h-9">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in visibilityOptions"
                                :key="option.value"
                                :value="option.value"
                                :disabled="
                                    option.value === 'organization' &&
                                    !canShareWithOrg
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <component
                                        :is="
                                            option.value === 'organization'
                                                ? Users
                                                : Lock
                                        "
                                        class="size-4"
                                    />
                                    {{ option.label }}
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-[11px] text-ink-subtle">
                        {{
                            visibilityOptions.find(
                                (o) => o.value === form.visibility,
                            )?.description
                        }}
                    </p>
                </div>

                <!-- Footer actions — pill pattern to match the rest of admin-v2. -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        @click="handleClose"
                    >
                        {{ t('documents.upload.cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="!form.file || form.processing"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                    >
                        <Loader2 v-if="form.processing" class="size-3.5 animate-spin" />
                        <Upload v-else class="size-3.5" />
                        {{
                            form.processing
                                ? t('documents.upload.submitting')
                                : t('documents.upload.submit')
                        }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
