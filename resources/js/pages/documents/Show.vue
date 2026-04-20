<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import ArtifactEditor from '@/components/documents/ArtifactEditor.vue';
import ArtifactRenderer from '@/components/documents/ArtifactRenderer.vue';
import CodeMirrorViewer from '@/components/documents/CodeMirrorViewer.vue';
import MarkdownRenderer from '@/components/documents/MarkdownRenderer.vue';
import RawViewer from '@/components/documents/RawViewer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { Document, VisibilityOption } from '@/types/document';
import { Head, Link, router } from '@inertiajs/vue3';
import { useFullscreen } from '@vueuse/core';
import {
    Check,
    Database,
    Edit,
    ExternalLink,
    File,
    FileText,
    Folder,
    Globe,
    Link as LinkIcon,
    Lock,
    Maximize2,
    Share2,
    Sparkles,
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
    publicUrl: string | null;
}

const props = defineProps<Props>();

const showEditDialog = ref(false);
const showArtifactEditor = ref(false);
const showDeleteDialog = ref(false);
const showShareDialog = ref(false);
const copiedShareLink = ref(false);
const fullscreenTarget = ref<HTMLElement | null>(null);
const { isFullscreen, toggle: toggleFullscreen } = useFullscreen(
    fullscreenTarget,
);

// Public visibility is only offered when the document is an Artifact; filter
// the option out for other types so it doesn't appear in the Edit dialog.
const availableVisibilityOptions = computed(() =>
    props.visibilityOptions.filter(
        (o) => o.value !== 'public' || props.document.type === 'artifact',
    ),
);

function visibilityIcon(value: string) {
    return value === 'public' ? Globe : value === 'organization' ? Users : Lock;
}

// Canonical public URL for the document; used by both the standalone Share
// dialog and the inline preview inside the Edit Document dialog. Falls back
// to a client-computed path when the backend hasn't published the document
// yet (user is flipping to Public but hasn't saved).
const effectiveShareUrl = computed<string | null>(() => {
    if (props.publicUrl) return props.publicUrl;
    if (typeof window === 'undefined') return null;
    return `${window.location.origin}/share/d/${props.document.id}`;
});

async function copyShareLink(url?: string | null) {
    const target = url ?? props.publicUrl;
    if (!target) return;
    await navigator.clipboard.writeText(target);
    copiedShareLink.value = true;
    setTimeout(() => (copiedShareLink.value = false), 1500);
}
const editForm = ref({
    name: props.document.name,
    visibility: props.document.visibility,
});
const isSubmitting = ref(false);

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
    artifact:
        'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
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
        },
    );
};

const handleDelete = () => {
    isSubmitting.value = true;

    router.delete(
        DocumentController.destroy({ document: props.document.id }).url,
        {
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="document.name" />

    <AppLayoutV2 :title="t('app_v2.nav.documents')">
        <div class="mx-auto max-w-4xl space-y-6">
                <!-- Header -->
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-4">
                        <div class="rounded-xs bg-white/5 p-3">
                            <component
                                :is="getDocumentIcon(document.type)"
                                class="h-8 w-8 text-ink-muted"
                            />
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-[22px] font-semibold leading-tight text-ink">{{ document.name }}</h1>
                                <Badge
                                    :class="
                                        typeColors[document.type] ||
                                        typeColors.txt
                                    "
                                    variant="outline"
                                >
                                    {{ document.type.toUpperCase() }}
                                </Badge>
                                <component
                                    :is="
                                        document.visibility === 'organization'
                                            ? Users
                                            : Lock
                                    "
                                    class="h-4 w-4 text-muted-foreground"
                                    :title="
                                        document.visibility === 'organization'
                                            ? 'Shared with organization'
                                            : 'Private'
                                    "
                                />
                            </div>
                            <p
                                v-if="document.original_filename"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                {{ document.original_filename }}
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button v-if="temporaryUrl" @click="handleDownload">
                            <ExternalLink class="mr-2 h-4 w-4" />
                            {{ t('common.open') }}
                        </Button>
                        <Button
                            v-if="publicUrl"
                            variant="outline"
                            @click="showShareDialog = true"
                        >
                            <Share2 class="mr-2 h-4 w-4" />
                            {{ t('documents.show.share') }}
                        </Button>
                        <Button
                            v-if="
                                canEdit &&
                                document.type === 'artifact' &&
                                document.body !== null
                            "
                            variant="default"
                            @click="showArtifactEditor = true"
                        >
                            <Sparkles class="mr-2 h-4 w-4" />
                            {{ t('documents.show.edit_artifact') }}
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
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    File Size
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{ document.formatted_file_size || '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Type
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{ document.type.toUpperCase() }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Visibility
                                </dt>
                                <dd
                                    class="mt-1 flex items-center gap-2 text-sm"
                                >
                                    <component
                                        :is="
                                            document.visibility ===
                                            'organization'
                                                ? Users
                                                : Lock
                                        "
                                        class="h-4 w-4"
                                    />
                                    {{
                                        document.visibility === 'organization'
                                            ? 'Organization'
                                            : 'Private'
                                    }}
                                </dd>
                            </div>
                            <div v-if="document.folder">
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Folder
                                </dt>
                                <dd class="mt-1 text-sm">
                                    <Link
                                        :href="
                                            DocumentController.index({
                                                query: {
                                                    folder: document.folder.id,
                                                },
                                            }).url
                                        "
                                        class="flex items-center gap-1 text-primary hover:underline"
                                    >
                                        <Folder class="h-4 w-4" />
                                        {{ document.folder.name }}
                                    </Link>
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Created
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{
                                        new Date(
                                            document.created_at,
                                        ).toLocaleDateString()
                                    }}
                                </dd>
                            </div>
                            <div v-if="document.user">
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Owner
                                </dt>
                                <dd class="mt-1 text-sm">
                                    {{ document.user.name }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <!-- Inline Content Card (Raw / Rendered) -->
                <Card v-if="document.body !== null" class="mb-6">
                    <CardHeader>
                        <CardTitle>{{ t('documents.show.content') }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            ref="fullscreenTarget"
                            :class="[
                                'bg-background',
                                isFullscreen ? 'flex h-full w-full flex-col overflow-auto p-6' : '',
                            ]"
                        >
                            <Tabs
                                default-value="rendered"
                                :class="
                                    isFullscreen
                                        ? 'flex min-h-0 flex-1 flex-col'
                                        : ''
                                "
                            >
                                <div
                                    v-if="!isFullscreen"
                                    class="flex items-center justify-between"
                                >
                                    <TabsList>
                                        <TabsTrigger value="rendered">{{
                                            t('documents.show.rendered_tab')
                                        }}</TabsTrigger>
                                        <TabsTrigger value="raw">{{
                                            t('documents.show.raw_tab')
                                        }}</TabsTrigger>
                                    </TabsList>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="toggleFullscreen"
                                    >
                                        <Maximize2 class="mr-1 h-4 w-4" />
                                        {{ t('documents.show.fullscreen') }}
                                    </Button>
                                </div>
                                <TabsContent
                                    value="rendered"
                                    :class="
                                        isFullscreen
                                            ? 'mt-0 min-h-0 flex-1 overflow-auto'
                                            : 'mt-3'
                                    "
                                >
                                    <MarkdownRenderer
                                        v-if="document.type === 'md'"
                                        :source="document.body"
                                    />
                                    <ArtifactRenderer
                                        v-else-if="document.type === 'artifact'"
                                        :source="document.body"
                                    />
                                    <pre
                                        v-else
                                        class="whitespace-pre-wrap rounded border bg-card p-4 text-sm"
                                        >{{ document.body }}</pre
                                    >
                                </TabsContent>
                                <TabsContent
                                    value="raw"
                                    :class="
                                        isFullscreen
                                            ? 'mt-0 min-h-0 flex-1 overflow-auto'
                                            : 'mt-3'
                                    "
                                >
                                    <CodeMirrorViewer
                                        v-if="document.type === 'artifact'"
                                        :source="document.body"
                                        language="html"
                                    />
                                    <RawViewer
                                        v-else
                                        :source="document.body"
                                        :language="document.type"
                                    />
                                </TabsContent>
                            </Tabs>
                        </div>
                    </CardContent>
                </Card>

                <!-- Knowledge Bases Card -->
                <Card
                    v-if="
                        document.knowledge_bases &&
                        document.knowledge_bases.length > 0
                    "
                >
                    <CardHeader>
                        <CardTitle>Used in Knowledge Bases</CardTitle>
                        <CardDescription>
                            This document is attached to the following knowledge
                            bases
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-2">
                            <li
                                v-for="kb in document.knowledge_bases"
                                :key="kb.id"
                                class="flex items-center gap-2"
                            >
                                <Database
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                <span>{{ kb.name }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
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
                        <Select v-model="editForm.visibility">
                            <SelectTrigger>
                                <SelectValue placeholder="Select visibility" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in availableVisibilityOptions"
                                    :key="option.value"
                                    :value="option.value"
                                    :disabled="
                                        option.value === 'organization' &&
                                        !canShareWithOrg
                                    "
                                >
                                    <div class="flex items-center gap-2">
                                        <component
                                            :is="visibilityIcon(option.value)"
                                            class="h-4 w-4"
                                        />
                                        {{ option.label }}
                                    </div>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            {{
                                availableVisibilityOptions.find(
                                    (o) => o.value === editForm.visibility,
                                )?.description
                            }}
                        </p>
                    </div>

                    <div
                        v-if="
                            editForm.visibility === 'public' &&
                            effectiveShareUrl
                        "
                        class="space-y-2 rounded border bg-muted/40 p-3"
                    >
                        <Label>{{ t('documents.show.share_title') }}</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                :model-value="effectiveShareUrl"
                                readonly
                                class="flex-1"
                                @focus="(e: FocusEvent) => (e.target as HTMLInputElement)?.select()"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="shrink-0 gap-1"
                                @click="copyShareLink(effectiveShareUrl)"
                            >
                                <Check
                                    v-if="copiedShareLink"
                                    class="h-4 w-4"
                                />
                                <LinkIcon v-else class="h-4 w-4" />
                                {{
                                    copiedShareLink
                                        ? t('common.saved')
                                        : t('documents.show.copy_link')
                                }}
                            </Button>
                        </div>
                        <p
                            v-if="document.visibility !== 'public'"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('documents.show.share_pending') }}
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

        <!-- Share Dialog -->
        <Dialog v-if="publicUrl" v-model:open="showShareDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        t('documents.show.share_title')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ t('documents.show.share_description') }}
                    </DialogDescription>
                </DialogHeader>
                <div class="flex items-center gap-2">
                    <Input
                        :model-value="publicUrl"
                        readonly
                        class="flex-1"
                        @focus="(e: FocusEvent) => (e.target as HTMLInputElement)?.select()"
                    />
                    <Button
                        variant="outline"
                        size="sm"
                        class="gap-1"
                        @click="copyShareLink(publicUrl)"
                    >
                        <Check v-if="copiedShareLink" class="h-4 w-4" />
                        <LinkIcon v-else class="h-4 w-4" />
                        {{
                            copiedShareLink
                                ? t('common.saved')
                                : t('documents.show.copy_link')
                        }}
                    </Button>
                </div>
                <DialogFooter>
                    <Button as="a" :href="publicUrl" target="_blank">
                        <ExternalLink class="mr-2 h-4 w-4" />
                        {{ t('documents.show.open_public') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Artifact Editor Dialog -->
        <ArtifactEditor
            v-if="document.type === 'artifact' && document.body !== null"
            v-model:open="showArtifactEditor"
            :document-id="document.id"
            :initial-body="document.body"
        />

        <!-- Delete Dialog -->
        <Dialog v-model:open="showDeleteDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete Document</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete "{{ document.name }}"?
                        This action cannot be undone.
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
    </AppLayoutV2>
</template>
