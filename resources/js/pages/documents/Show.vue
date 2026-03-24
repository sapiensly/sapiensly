<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Document, VisibilityOption } from '@/types/document';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Database,
    Edit,
    ExternalLink,
    File,
    FileText,
    Folder,
    Lock,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    document: Document;
    temporaryUrl: string | null;
    canEdit: boolean;
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
}

const props = defineProps<Props>();

const showEditDialog = ref(false);
const showDeleteDialog = ref(false);
const editForm = ref({
    name: props.document.name,
    visibility: props.document.visibility,
});
const isSubmitting = ref(false);

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const crumbs: BreadcrumbItem[] = [
        { title: t('documents.show.documents'), href: DocumentController.index().url },
    ];

    if (props.document.folder) {
        crumbs.push({
            title: props.document.folder.name,
            href: DocumentController.index({ query: { folder: props.document.folder.id } }).url,
        });
    }

    crumbs.push({ title: props.document.name, href: '#' });

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

const typeColors: Record<string, string> = {
    pdf: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    txt: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400',
    docx: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    md: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    csv: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    json: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
};

const handleDownload = () => {
    if (props.temporaryUrl) {
        window.open(props.temporaryUrl, '_blank');
    }
};

const handleEdit = () => {
    isSubmitting.value = true;

    router.put(
        DocumentController.update({ document: props.document.id }).url,
        editForm.value,
        {
            onFinish: () => {
                isSubmitting.value = false;
                showEditDialog.value = false;
            },
        }
    );
};

const handleDelete = () => {
    isSubmitting.value = true;

    router.delete(DocumentController.destroy({ document: props.document.id }).url, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};
</script>

<template>
    <Head :title="document.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <!-- Header -->
                <div class="mb-8 flex items-start justify-between">
                    <div class="flex items-start gap-4">
                        <div class="rounded-lg bg-muted p-3">
                            <component
                                :is="getDocumentIcon(document.type)"
                                class="h-8 w-8 text-muted-foreground"
                            />
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <Heading :title="document.name" />
                                <Badge
                                    :class="typeColors[document.type] || typeColors.txt"
                                    variant="outline"
                                >
                                    {{ document.type.toUpperCase() }}
                                </Badge>
                                <component
                                    :is="document.visibility === 'organization' ? Users : Lock"
                                    class="h-4 w-4 text-muted-foreground"
                                    :title="document.visibility === 'organization' ? 'Shared with organization' : 'Private'"
                                />
                            </div>
                            <p v-if="document.original_filename" class="mt-1 text-sm text-muted-foreground">
                                {{ document.original_filename }}
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button
                            v-if="temporaryUrl"
                            @click="handleDownload"
                        >
                            <ExternalLink class="mr-2 h-4 w-4" />
                            {{ t('common.open') }}
                        </Button>
                        <Button
                            v-if="canEdit"
                            variant="outline"
                            @click="showEditDialog = true"
                        >
                            <Edit class="mr-2 h-4 w-4" />
                            {{ t('common.edit') }}
                        </Button>
                        <Button
                            v-if="canEdit"
                            variant="destructive"
                            @click="showDeleteDialog = true"
                        >
                            <Trash2 class="mr-2 h-4 w-4" />
                            {{ t('common.delete') }}
                        </Button>
                    </div>
                </div>

                <!-- Details Card -->
                <Card class="mb-6">
                    <CardHeader>
                        <CardTitle>{{ t('documents.show.title') }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-muted-foreground">
                                    File Size
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{ document.formatted_file_size || '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-muted-foreground">
                                    Type
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{ document.type.toUpperCase() }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-muted-foreground">
                                    Visibility
                                </dt>
                                <dd class="mt-1 flex items-center gap-2 text-sm">
                                    <component
                                        :is="document.visibility === 'organization' ? Users : Lock"
                                        class="h-4 w-4"
                                    />
                                    {{ document.visibility === 'organization' ? 'Organization' : 'Private' }}
                                </dd>
                            </div>
                            <div v-if="document.folder">
                                <dt class="text-sm font-medium text-muted-foreground">
                                    Folder
                                </dt>
                                <dd class="mt-1 text-sm">
                                    <Link
                                        :href="DocumentController.index({ query: { folder: document.folder.id } }).url"
                                        class="flex items-center gap-1 text-primary hover:underline"
                                    >
                                        <Folder class="h-4 w-4" />
                                        {{ document.folder.name }}
                                    </Link>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-muted-foreground">
                                    Created
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{ new Date(document.created_at).toLocaleDateString() }}
                                </dd>
                            </div>
                            <div v-if="document.user">
                                <dt class="text-sm font-medium text-muted-foreground">
                                    Owner
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{ document.user.name }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <!-- Knowledge Bases Card -->
                <Card v-if="document.knowledge_bases && document.knowledge_bases.length > 0">
                    <CardHeader>
                        <CardTitle>Used in Knowledge Bases</CardTitle>
                        <CardDescription>
                            This document is attached to the following knowledge bases
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-2">
                            <li
                                v-for="kb in document.knowledge_bases"
                                :key="kb.id"
                                class="flex items-center gap-2"
                            >
                                <Database class="h-4 w-4 text-muted-foreground" />
                                <span>{{ kb.name }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>

        <!-- Edit Dialog -->
        <Dialog v-model:open="showEditDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Document</DialogTitle>
                    <DialogDescription>
                        Update the document name and visibility settings.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-4">
                    <div class="space-y-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="editForm.name"
                            placeholder="Document name"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="visibility">Visibility</Label>
                        <Select v-model="editForm.visibility" :disabled="!canShareWithOrg">
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
                            {{ visibilityOptions.find(o => o.value === editForm.visibility)?.description }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="showEditDialog = false">
                        Cancel
                    </Button>
                    <Button :disabled="isSubmitting" @click="handleEdit">
                        Save Changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Delete Dialog -->
        <Dialog v-model:open="showDeleteDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete Document</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete "{{ document.name }}"? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button variant="outline" @click="showDeleteDialog = false">
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="isSubmitting"
                        @click="handleDelete"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
