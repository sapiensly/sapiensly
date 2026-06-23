<script setup lang="ts">
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import * as KnowledgeBaseDocumentController from '@/actions/App/Http/Controllers/KnowledgeBaseDocumentController';
import DocumentSelectorDialog from '@/components/documents/DocumentSelectorDialog.vue';
import DocumentUploadDialog from '@/components/documents/DocumentUploadDialog.vue';
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
import { Textarea } from '@/components/ui/textarea';
import echo from '@/echo';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type {
    Document,
    GroupedFolders,
    VisibilityOption,
} from '@/types/document';
import type {
    AskKbResult,
    DocumentTypeOption,
    IngestionCostEstimate,
    KnowledgeBase,
    KnowledgeBaseDocument,
} from '@/types/knowledge-base';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Clock,
    FileText,
    FolderPlus,
    Loader2,
    MoreVertical,
    Pencil,
    Plus,
    RefreshCw,
    Send,
    Sparkles,
    Trash2,
    Upload,
} from '@lucide/vue';
import axios from 'axios';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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

// Real-time status tracking via WebSocket
const statusOverrides = reactive<
    Record<string, { status: string; errorMessage?: string | null }>
>({});
const kbStatusOverride = ref<string | null>(null);
const kbDocCountOverride = ref<number | null>(null);
const kbChunkCountOverride = ref<number | null>(null);

const channel = echo.private(`knowledge-base.${props.knowledgeBase.id}`);

channel.listen(
    '.DocumentStatusChanged',
    (data: {
        documentId: string;
        status: string;
        errorMessage?: string | null;
        knowledgeBaseStatus?: string | null;
        documentCount?: number | null;
        chunkCount?: number | null;
    }) => {
        statusOverrides[data.documentId] = {
            status: data.status,
            errorMessage: data.errorMessage,
        };

        if (data.knowledgeBaseStatus) {
            kbStatusOverride.value = data.knowledgeBaseStatus;
        }
        if (data.documentCount !== null && data.documentCount !== undefined) {
            kbDocCountOverride.value = data.documentCount;
        }
        if (data.chunkCount !== null && data.chunkCount !== undefined) {
            kbChunkCountOverride.value = data.chunkCount;
        }
    },
);

onBeforeUnmount(() => {
    echo.leave(`knowledge-base.${props.knowledgeBase.id}`);
});

const currentKbStatus = computed(
    () => kbStatusOverride.value ?? props.knowledgeBase.status,
);
const currentDocCount = computed(
    () => kbDocCountOverride.value ?? props.knowledgeBase.document_count,
);
const currentChunkCount = computed(
    () => kbChunkCountOverride.value ?? props.knowledgeBase.chunk_count,
);

// Combine legacy documents and new attached documents
const allDocuments = computed(() => {
    const legacy = props.knowledgeBase.documents || [];
    const attached = props.knowledgeBase.attached_documents || [];

    // Map attached documents to have similar structure as legacy
    const mappedAttached = attached.map((doc) => ({
        id: doc.id,
        original_filename: doc.original_filename,
        name: doc.name,
        source: doc.name,
        type: doc.type,
        embedding_status: doc.pivot?.embedding_status || 'pending',
        error_message: doc.pivot?.error_message || null,
        ingestion_cost: doc.pivot?.ingestion_cost ?? null,
        extraction_method: doc.pivot?.extraction_method ?? null,
        page_count: doc.pivot?.page_count ?? null,
        isAttached: true, // Flag to differentiate
    }));

    // Map legacy documents
    const mappedLegacy = legacy.map((doc) => ({
        ...doc,
        isAttached: false,
    }));

    // Apply real-time status overrides
    return [...mappedLegacy, ...mappedAttached].map((doc) => {
        const override = statusOverrides[doc.id];
        if (override) {
            return {
                ...doc,
                embedding_status: override.status,
                error_message: override.errorMessage ?? doc.error_message,
            };
        }
        return doc;
    });
});

const formatCost = (cost: number | string | null | undefined): string => {
    const n = Number(cost ?? 0);
    if (!Number.isFinite(n) || n <= 0) return '$0';
    if (n < 0.0001) return '<$0.0001';
    return '$' + n.toFixed(4);
};

// "OCR · 5p" / "text" — how a document was extracted.
const methodLabel = (
    method: string | null | undefined,
    pages: number | null | undefined,
): string => {
    if (method === 'ocr') return pages ? `OCR · ${pages}p` : 'OCR';
    if (method === 'php') return 'text';
    return '';
};

const documentName = (id: string): string => {
    const doc = props.availableDocuments.find((d) => d.id === id);
    return doc?.original_filename ?? doc?.name ?? id;
};

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
        KnowledgeBaseController.destroy({
            knowledge_base: props.knowledgeBase.id,
        }).url,
    );
};

const documentTypeLabel = (type: string) => {
    const found = props.documentTypes.find((dt) => dt.value === type);
    return found?.label ?? type.toUpperCase();
};

const deleteDocument = (
    doc: KnowledgeBaseDocument & { isAttached?: boolean },
) => {
    if (
        !confirm(`Remove "${doc.original_filename ?? doc.name ?? doc.source}"?`)
    )
        return;

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

const reprocessDocument = (
    doc: KnowledgeBaseDocument & { isAttached?: boolean },
) => {
    // Optimistically update status
    statusOverrides[doc.id] = { status: 'pending', errorMessage: null };

    const url = doc.isAttached
        ? KnowledgeBaseController.reprocessDocument({
              knowledge_base: props.knowledgeBase.id,
              document: doc.id,
          }).url
        : KnowledgeBaseDocumentController.reprocess({
              knowledge_base: props.knowledgeBase.id,
              document: doc.id,
          }).url;

    router.post(url, {}, { preserveScroll: true, preserveState: true });
};

// Cost preview shown after selecting documents, before attaching/processing.
const showCostPreview = ref(false);
const costPreviewLoading = ref(false);
const pendingAttachIds = ref<string[]>([]);
const costPreviewItems = ref<
    Array<{
        id: string;
        name: string;
        estimate: IngestionCostEstimate | null;
        error: boolean;
    }>
>([]);

const totalPreviewCost = computed(() =>
    costPreviewItems.value.reduce(
        (sum, item) => sum + (item.estimate?.total_cost ?? 0),
        0,
    ),
);

const fetchEstimate = async (id: string): Promise<IngestionCostEstimate> => {
    const url = KnowledgeBaseController.estimateCost({
        knowledge_base: props.knowledgeBase.id,
        document: id,
    }).url;
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('estimate failed');
    return (await res.json()) as IngestionCostEstimate;
};

const handleDocumentsSelected = async (documentIds: string[]) => {
    if (documentIds.length === 0) return;

    pendingAttachIds.value = documentIds;
    costPreviewItems.value = documentIds.map((id) => ({
        id,
        name: documentName(id),
        estimate: null,
        error: false,
    }));
    showCostPreview.value = true;
    costPreviewLoading.value = true;

    await Promise.all(
        documentIds.map(async (id) => {
            const item = costPreviewItems.value.find((i) => i.id === id);
            try {
                const estimate = await fetchEstimate(id);
                if (item) item.estimate = estimate;
            } catch {
                if (item) item.error = true;
            }
        }),
    );

    costPreviewLoading.value = false;
};

const confirmAttach = () => {
    const ids = pendingAttachIds.value;
    showCostPreview.value = false;

    router.post(
        KnowledgeBaseController.attachDocuments({
            knowledge_base: props.knowledgeBase.id,
        }).url,
        { document_ids: ids },
        { preserveScroll: true },
    );
};

// --- Ask your KB (single-KB QA / retrieval debug) ---
const askQuery = ref('');
const askLoading = ref(false);
const askError = ref<string | null>(null);
const askResult = ref<AskKbResult | null>(null);
const askElapsedMs = ref(0);
let askTimer: number | null = null;

const formatMs = (ms: number): string =>
    ms >= 1000 ? `${(ms / 1000).toFixed(2)}s` : `${Math.round(ms)}ms`;

const submitAsk = async () => {
    const q = askQuery.value.trim();
    if (!q || askLoading.value) return;

    askLoading.value = true;
    askError.value = null;
    askResult.value = null;
    askElapsedMs.value = 0;

    const started = performance.now();
    askTimer = window.setInterval(() => {
        askElapsedMs.value = performance.now() - started;
    }, 100);

    try {
        const res = await axios.post(
            KnowledgeBaseController.ask({
                knowledge_base: props.knowledgeBase.id,
            }).url,
            { query: q },
        );
        askResult.value = res.data as AskKbResult;
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        askError.value =
            err.response?.data?.message ??
            'Failed to query the knowledge base.';
    } finally {
        askLoading.value = false;
        if (askTimer) {
            clearInterval(askTimer);
            askTimer = null;
        }
    }
};

onBeforeUnmount(() => {
    if (askTimer) clearInterval(askTimer);
});
</script>

<template>
    <Head :title="knowledgeBase.name" />

    <AppLayoutV2 :title="t('app_v2.nav.knowledge_base')">
        <div class="mx-auto max-w-4xl space-y-6">
            <div class="flex items-start justify-between">
                <div>
                    <div class="mb-2 flex items-center gap-3">
                        <h1
                            class="text-[22px] leading-tight font-semibold text-ink"
                        >
                            {{ knowledgeBase.name }}
                        </h1>
                        <Badge :variant="statusVariant(currentKbStatus)">
                            <Loader2
                                v-if="currentKbStatus === 'processing'"
                                class="mr-1 h-3 w-3 animate-spin"
                            />
                            {{ currentKbStatus }}
                        </Badge>
                    </div>
                    <p
                        v-if="knowledgeBase.description"
                        class="text-xs text-ink-muted"
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
                            {{ t('common.edit') }}
                        </Link>
                    </Button>
                    <Dialog>
                        <DialogTrigger as-child>
                            <Button variant="destructive">
                                <Trash2 class="mr-2 h-4 w-4" />
                                {{ t('common.delete') }}
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>{{
                                    t('knowledge_bases.show.delete_kb')
                                }}</DialogTitle>
                                <DialogDescription>
                                    {{ t('common.confirm_delete') }} "{{
                                        knowledgeBase.name
                                    }}"?
                                    {{ t('common.action_irreversible') }}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="outline">{{
                                        t('common.cancel')
                                    }}</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    @click="deleteKnowledgeBase"
                                >
                                    {{ t('common.delete') }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <div class="space-y-8">
                <!-- Ask your KB: single-KB QA for testing/debugging retrieval -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Sparkles class="h-4 w-4" />
                            Ask your KB
                        </CardTitle>
                        <CardDescription>
                            Test retrieval — answers come only from this
                            knowledge base, with timing and retrieval details
                            for each question.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex gap-2">
                            <Textarea
                                v-model="askQuery"
                                rows="2"
                                placeholder="Ask a question about this knowledge base…"
                                class="flex-1"
                                @keydown.enter.exact.prevent="submitAsk"
                            />
                            <Button
                                :disabled="!askQuery.trim() || askLoading"
                                @click="submitAsk"
                            >
                                <Loader2
                                    v-if="askLoading"
                                    class="mr-2 h-4 w-4 animate-spin"
                                />
                                <Send v-else class="mr-2 h-4 w-4" />
                                Ask
                            </Button>
                        </div>

                        <div
                            v-if="askLoading"
                            class="flex items-center gap-2 text-sm text-ink-subtle"
                        >
                            <Clock class="h-4 w-4" />
                            {{ (askElapsedMs / 1000).toFixed(1) }}s
                        </div>

                        <p v-if="askError" class="text-sm text-destructive">
                            {{ askError }}
                        </p>

                        <div v-if="askResult" class="space-y-3">
                            <div
                                class="rounded-md border border-medium bg-surface p-3 text-sm whitespace-pre-wrap"
                            >
                                {{ askResult.answer }}
                            </div>

                            <div
                                class="flex flex-wrap items-center gap-2 text-xs"
                            >
                                <Badge variant="secondary">
                                    total
                                    {{ formatMs(askResult.timing_ms.total) }}
                                </Badge>
                                <Badge variant="outline">
                                    retrieval
                                    {{
                                        formatMs(askResult.timing_ms.retrieval)
                                    }}
                                </Badge>
                                <Badge variant="outline">
                                    generation
                                    {{
                                        formatMs(askResult.timing_ms.generation)
                                    }}
                                </Badge>
                                <Badge variant="outline">
                                    {{ askResult.retrieval.chunk_count }} chunks
                                </Badge>
                                <Badge
                                    v-if="askResult.retrieval.reranked"
                                    variant="default"
                                >
                                    reranked{{
                                        askResult.retrieval.rerank_model
                                            ? ` · ${askResult.retrieval.rerank_model}`
                                            : ''
                                    }}
                                </Badge>
                                <Badge variant="outline">
                                    {{ askResult.retrieval.embedding_model }}
                                </Badge>
                            </div>

                            <div
                                v-if="askResult.retrieval.chunks.length"
                                class="space-y-2"
                            >
                                <p class="text-xs font-medium text-ink-muted">
                                    Retrieved context
                                </p>
                                <div
                                    v-for="(chunk, i) in askResult.retrieval
                                        .chunks"
                                    :key="i"
                                    class="rounded-md border border-medium px-3 py-2 text-xs"
                                >
                                    <div
                                        class="mb-1 flex items-center justify-between gap-2 text-ink-subtle"
                                    >
                                        <span class="truncate font-medium">{{
                                            chunk.source
                                        }}</span>
                                        <span class="shrink-0 tabular-nums">
                                            <template
                                                v-if="
                                                    chunk.rerank_score !== null
                                                "
                                                >rerank
                                                {{ chunk.rerank_score }} ·
                                            </template>
                                            sim {{ chunk.similarity ?? '—' }}
                                        </span>
                                    </div>
                                    <p class="text-ink-muted">
                                        {{ chunk.snippet }}
                                    </p>
                                </div>
                            </div>
                            <p v-else class="text-xs text-ink-subtle">
                                No chunks matched — the answer falls back to
                                "not in this knowledge base".
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{
                            t('knowledge_bases.show.configuration')
                        }}</CardTitle>
                        <CardDescription>
                            {{ t('knowledge_bases.show.config_description') }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Chunk Size
                                </dt>
                                <dd class="mt-1">
                                    {{
                                        knowledgeBase.config?.chunk_size ?? 1000
                                    }}
                                    characters
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Chunk Overlap
                                </dt>
                                <dd class="mt-1">
                                    {{
                                        knowledgeBase.config?.chunk_overlap ??
                                        200
                                    }}
                                    characters
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Documents
                                </dt>
                                <dd class="mt-1">{{ currentDocCount }}</dd>
                            </div>
                            <div>
                                <dt
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    Chunks
                                </dt>
                                <dd class="mt-1">
                                    {{ currentChunkCount }}
                                </dd>
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
                                <DropdownMenuItem
                                    @click="showDocumentSelector = true"
                                >
                                    <FolderPlus class="mr-2 h-4 w-4" />
                                    Add Existing Document
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    @click="showUploadDialog = true"
                                >
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
                            <FileText
                                class="mx-auto h-8 w-8 text-muted-foreground"
                            />
                            <p class="mt-2 text-sm text-muted-foreground">
                                No documents yet. Upload documents to populate
                                this knowledge base.
                            </p>
                        </div>

                        <div v-else class="space-y-3">
                            <Card v-for="doc in allDocuments" :key="doc.id">
                                <CardContent
                                    class="flex items-center justify-between py-4"
                                >
                                    <div class="flex items-center gap-3">
                                        <FileText
                                            class="h-5 w-5 text-muted-foreground"
                                        />
                                        <div>
                                            <p class="font-medium">
                                                {{
                                                    doc.original_filename ??
                                                    doc.source
                                                }}
                                            </p>
                                            <div
                                                class="flex items-center gap-2 text-sm text-muted-foreground"
                                            >
                                                <span>{{
                                                    documentTypeLabel(doc.type)
                                                }}</span>
                                                <span
                                                    v-if="
                                                        doc.ingestion_cost !=
                                                        null
                                                    "
                                                    :title="
                                                        methodLabel(
                                                            doc.extraction_method,
                                                            doc.page_count,
                                                        )
                                                    "
                                                >
                                                    ·
                                                    {{
                                                        formatCost(
                                                            doc.ingestion_cost,
                                                        )
                                                    }}
                                                    <template
                                                        v-if="
                                                            doc.extraction_method
                                                        "
                                                    >
                                                        ·
                                                        {{
                                                            methodLabel(
                                                                doc.extraction_method,
                                                                doc.page_count,
                                                            )
                                                        }}
                                                    </template>
                                                </span>
                                                <span
                                                    v-if="doc.error_message"
                                                    class="text-destructive"
                                                >
                                                    -
                                                    {{ doc.error_message }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Badge
                                            :variant="
                                                statusVariant(
                                                    doc.embedding_status,
                                                )
                                            "
                                        >
                                            <Loader2
                                                v-if="
                                                    doc.embedding_status ===
                                                    'processing'
                                                "
                                                class="mr-1 h-3 w-3 animate-spin"
                                            />
                                            {{ doc.embedding_status }}
                                        </Badge>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="h-8 w-8"
                                                >
                                                    <MoreVertical
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    v-if="
                                                        doc.embedding_status ===
                                                            'ready' ||
                                                        doc.embedding_status ===
                                                            'failed'
                                                    "
                                                    @click="
                                                        reprocessDocument(doc)
                                                    "
                                                >
                                                    <RefreshCw
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    {{
                                                        doc.embedding_status ===
                                                        'failed'
                                                            ? 'Retry Processing'
                                                            : 'Re-process'
                                                    }}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    class="text-destructive focus:text-destructive"
                                                    @click="deleteDocument(doc)"
                                                >
                                                    <Trash2
                                                        class="mr-2 h-4 w-4"
                                                    />
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

        <!-- Document Selector Dialog -->
        <DocumentSelectorDialog
            v-model:open="showDocumentSelector"
            :documents="availableDocuments"
            :folders="folders"
            :exclude-document-ids="attachedDocumentIds"
            @select="handleDocumentsSelected"
        />

        <!-- Cost preview before attaching/processing selected documents -->
        <Dialog v-model:open="showCostPreview">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Estimated ingestion cost</DialogTitle>
                    <DialogDescription>
                        Projected cost to process the selected document(s) into
                        this knowledge base (OCR + embeddings). Actual cost is
                        recorded once processing completes.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-2">
                    <div
                        v-for="item in costPreviewItems"
                        :key="item.id"
                        class="flex items-center justify-between gap-3 rounded-md border border-medium px-3 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ item.name }}</p>
                            <p
                                v-if="item.estimate"
                                class="text-xs text-ink-subtle"
                            >
                                {{
                                    item.estimate.method === 'ocr'
                                        ? `OCR (${item.estimate.engine}) · ${item.estimate.pages}p`
                                        : 'In-process text'
                                }}
                                · ~{{
                                    item.estimate.estimated_tokens.toLocaleString()
                                }}
                                tokens
                            </p>
                            <p
                                v-else-if="item.error"
                                class="text-xs text-destructive"
                            >
                                Could not estimate
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <Loader2
                                v-if="!item.estimate && !item.error"
                                class="h-4 w-4 animate-spin text-ink-subtle"
                            />
                            <span v-else-if="item.estimate" class="font-medium">
                                {{ formatCost(item.estimate.total_cost) }}
                            </span>
                            <span v-else>—</span>
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center justify-between border-t border-medium pt-3 text-sm font-medium"
                >
                    <span>Estimated total</span>
                    <span>{{ formatCost(totalPreviewCost) }}</span>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="showCostPreview = false">
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        :disabled="costPreviewLoading"
                        @click="confirmAttach"
                    >
                        Add &amp; process
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Upload Dialog -->
        <DocumentUploadDialog
            v-model:open="showUploadDialog"
            :visibility-options="visibilityOptions"
            :can-share-with-org="canShareWithOrg"
            :folders="folders"
            :show-folder-selector="true"
            :knowledge-base-id="knowledgeBase.id"
        />
    </AppLayoutV2>
</template>
