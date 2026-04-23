<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import ArtifactEditor from '@/components/documents/ArtifactEditor.vue';
import ArtifactRenderer from '@/components/documents/ArtifactRenderer.vue';
import CodeMirrorViewer from '@/components/documents/CodeMirrorViewer.vue';
import MarkdownRenderer from '@/components/documents/MarkdownRenderer.vue';
import RawViewer from '@/components/documents/RawViewer.vue';
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
import type { Component } from 'vue';
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

function visibilityIcon(value: string): Component {
    return value === 'public' ? Globe : value === 'organization' ? Users : Lock;
}

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

function getDocumentIcon(type: string): Component {
    switch (type) {
        case 'pdf':
            return FileText;
        default:
            return File;
    }
}

// Per-type tint map — matches the list card pills in documents/Index.vue so
// the detail page + list entry read as the same visual language.
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

function handleDownload() {
    if (props.temporaryUrl) {
        window.open(props.temporaryUrl, '_blank');
    }
}

function handleEdit() {
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
}

function handleDelete() {
    isSubmitting.value = true;
    router.delete(
        DocumentController.destroy({ document: props.document.id }).url,
        {
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="document.name" />

    <AppLayoutV2 :title="t('app_v2.nav.documents')">
        <div class="mx-auto max-w-5xl space-y-5">
            <!-- Header: tinted icon tile + name + type/visibility pills + actions. -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xs"
                        :style="{
                            backgroundColor: `color-mix(in oklab, ${tintForType(document.type)} 15%, transparent)`,
                            color: tintForType(document.type),
                        }"
                    >
                        <component :is="getDocumentIcon(document.type)" class="size-5" />
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-[22px] font-semibold leading-tight text-ink">
                                {{ document.name }}
                            </h1>
                            <span
                                class="inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                :style="{
                                    color: tintForType(document.type),
                                    borderColor: `color-mix(in oklab, ${tintForType(document.type)} 45%, transparent)`,
                                }"
                            >
                                {{ document.type }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-pill border border-soft bg-white/5 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                                :title="
                                    document.visibility === 'public'
                                        ? 'Public'
                                        : document.visibility === 'organization'
                                          ? 'Shared with organization'
                                          : 'Private'
                                "
                            >
                                <component
                                    :is="visibilityIcon(document.visibility)"
                                    class="size-3"
                                />
                                {{ document.visibility }}
                            </span>
                        </div>
                        <p
                            v-if="document.original_filename"
                            class="truncate text-xs text-ink-muted"
                        >
                            {{ document.original_filename }}
                        </p>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <button
                        v-if="temporaryUrl"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        @click="handleDownload"
                    >
                        <ExternalLink class="size-3.5" />
                        {{ t('common.open') }}
                    </button>
                    <button
                        v-if="publicUrl"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        @click="showShareDialog = true"
                    >
                        <Share2 class="size-3.5" />
                        {{ t('documents.show.share') }}
                    </button>
                    <Link
                        v-if="canEdit && document.type === 'artifact' && document.body !== null"
                        :href="`/documents/${document.id}/edit`"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-accent-cyan/40 bg-accent-cyan/10 px-3.5 py-1.5 text-xs font-medium text-accent-cyan transition-colors hover:bg-accent-cyan/20"
                    >
                        <Sparkles class="size-3.5" />
                        {{ t('documents.show.edit_artifact') }}
                    </Link>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        @click="showEditDialog = true"
                    >
                        <Edit class="size-3.5" />
                        {{ t('common.edit') }}
                    </button>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20"
                        @click="showDeleteDialog = true"
                    >
                        <Trash2 class="size-3.5" />
                        {{ t('common.delete') }}
                    </button>
                </div>
            </div>

            <!-- Details — 2-column grid of labeled fields on a single bg-navy card. -->
            <section class="rounded-sp-sm border border-soft bg-navy p-5">
                <h2
                    class="mb-4 text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                >
                    {{ t('documents.show.title') }}
                </h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] text-ink-subtle">File Size</dt>
                        <dd class="mt-0.5 text-sm text-ink">
                            {{ document.formatted_file_size || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] text-ink-subtle">Type</dt>
                        <dd class="mt-0.5 text-sm text-ink uppercase">
                            {{ document.type }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] text-ink-subtle">Visibility</dt>
                        <dd class="mt-0.5 flex items-center gap-1.5 text-sm text-ink">
                            <component
                                :is="visibilityIcon(document.visibility)"
                                class="size-3.5 text-ink-subtle"
                            />
                            <span class="capitalize">{{ document.visibility }}</span>
                        </dd>
                    </div>
                    <div v-if="document.folder">
                        <dt class="text-[11px] text-ink-subtle">Folder</dt>
                        <dd class="mt-0.5 text-sm">
                            <Link
                                :href="
                                    DocumentController.index({
                                        query: { folder: document.folder.id },
                                    }).url
                                "
                                class="inline-flex items-center gap-1 text-accent-blue hover:underline"
                            >
                                <Folder class="size-3.5" />
                                {{ document.folder.name }}
                            </Link>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] text-ink-subtle">Created</dt>
                        <dd class="mt-0.5 text-sm text-ink">
                            {{ new Date(document.created_at).toLocaleDateString() }}
                        </dd>
                    </div>
                    <div v-if="document.user">
                        <dt class="text-[11px] text-ink-subtle">Owner</dt>
                        <dd class="mt-0.5 text-sm text-ink">
                            {{ document.user.name }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Inline Content (Raw / Rendered tabs). -->
            <section
                v-if="document.body !== null"
                class="rounded-sp-sm border border-soft bg-navy p-5"
            >
                <h2
                    class="mb-4 text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                >
                    {{ t('documents.show.content') }}
                </h2>
                <div
                    ref="fullscreenTarget"
                    :class="[
                        isFullscreen
                            ? 'flex h-full w-full flex-col overflow-auto bg-navy-deep p-6'
                            : '',
                    ]"
                >
                    <Tabs
                        default-value="rendered"
                        :class="isFullscreen ? 'flex min-h-0 flex-1 flex-col' : ''"
                    >
                        <div
                            v-if="!isFullscreen"
                            class="flex items-center justify-between"
                        >
                            <TabsList>
                                <TabsTrigger value="rendered">
                                    {{ t('documents.show.rendered_tab') }}
                                </TabsTrigger>
                                <TabsTrigger value="raw">
                                    {{ t('documents.show.raw_tab') }}
                                </TabsTrigger>
                            </TabsList>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink-muted transition-colors hover:border-strong hover:text-ink"
                                @click="toggleFullscreen"
                            >
                                <Maximize2 class="size-3.5" />
                                {{ t('documents.show.fullscreen') }}
                            </button>
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
                                class="whitespace-pre-wrap rounded-xs border border-soft bg-white/[0.03] p-4 text-sm text-ink"
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
            </section>

            <!-- Knowledge Bases. -->
            <section
                v-if="document.knowledge_bases && document.knowledge_bases.length > 0"
                class="rounded-sp-sm border border-soft bg-navy p-5"
            >
                <div class="mb-4">
                    <h2
                        class="text-[10px] font-semibold tracking-wider text-ink-subtle uppercase"
                    >
                        Used in Knowledge Bases
                    </h2>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        This document is attached to the following knowledge bases
                    </p>
                </div>
                <ul class="space-y-1.5">
                    <li
                        v-for="kb in document.knowledge_bases"
                        :key="kb.id"
                        class="flex items-center gap-2 rounded-xs border border-soft bg-white/[0.03] px-3 py-2 text-sm text-ink"
                    >
                        <Database class="size-3.5 text-ink-subtle" />
                        <span>{{ kb.name }}</span>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Edit Dialog. -->
        <Dialog v-model:open="showEditDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Document</DialogTitle>
                    <DialogDescription>
                        Update the document name and visibility settings.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-4">
                    <div class="space-y-1.5">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="editForm.name"
                            placeholder="Document name"
                            class="h-9"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="visibility">Visibility</Label>
                        <Select v-model="editForm.visibility">
                            <SelectTrigger class="h-9">
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
                                            class="size-3.5"
                                        />
                                        {{ option.label }}
                                    </div>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-[11px] text-ink-subtle">
                            {{
                                availableVisibilityOptions.find(
                                    (o) => o.value === editForm.visibility,
                                )?.description
                            }}
                        </p>
                    </div>

                    <div
                        v-if="editForm.visibility === 'public' && effectiveShareUrl"
                        class="space-y-2 rounded-xs border border-soft bg-white/[0.03] p-3"
                    >
                        <Label>{{ t('documents.show.share_title') }}</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                :model-value="effectiveShareUrl"
                                readonly
                                class="h-9 flex-1"
                                @focus="(e: FocusEvent) => (e.target as HTMLInputElement)?.select()"
                            />
                            <button
                                type="button"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                                @click="copyShareLink(effectiveShareUrl)"
                            >
                                <Check v-if="copiedShareLink" class="size-3.5" />
                                <LinkIcon v-else class="size-3.5" />
                                {{
                                    copiedShareLink
                                        ? t('common.saved')
                                        : t('documents.show.copy_link')
                                }}
                            </button>
                        </div>
                        <p
                            v-if="document.visibility !== 'public'"
                            class="text-[11px] text-ink-subtle"
                        >
                            {{ t('documents.show.share_pending') }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        @click="showEditDialog = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="isSubmitting"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover disabled:opacity-50"
                        @click="handleEdit"
                    >
                        Save Changes
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Share Dialog. -->
        <Dialog v-if="publicUrl" v-model:open="showShareDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ t('documents.show.share_title') }}</DialogTitle>
                    <DialogDescription>
                        {{ t('documents.show.share_description') }}
                    </DialogDescription>
                </DialogHeader>
                <div class="flex items-center gap-2">
                    <Input
                        :model-value="publicUrl"
                        readonly
                        class="h-9 flex-1"
                        @focus="(e: FocusEvent) => (e.target as HTMLInputElement)?.select()"
                    />
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        @click="copyShareLink(publicUrl)"
                    >
                        <Check v-if="copiedShareLink" class="size-3.5" />
                        <LinkIcon v-else class="size-3.5" />
                        {{
                            copiedShareLink
                                ? t('common.saved')
                                : t('documents.show.copy_link')
                        }}
                    </button>
                </div>
                <DialogFooter>
                    <a
                        :href="publicUrl"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <ExternalLink class="size-3.5" />
                        {{ t('documents.show.open_public') }}
                    </a>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Artifact Editor Dialog — component owns its own chrome. -->
        <ArtifactEditor
            v-if="document.type === 'artifact' && document.body !== null"
            v-model:open="showArtifactEditor"
            :document-id="document.id"
            :initial-body="document.body"
        />

        <!-- Delete confirmation. -->
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
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                        @click="showDeleteDialog = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="isSubmitting"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/40 bg-sp-danger/10 px-3.5 py-1.5 text-xs text-sp-danger transition-colors hover:bg-sp-danger/20 disabled:opacity-50"
                        @click="handleDelete"
                    >
                        Delete
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayoutV2>
</template>
