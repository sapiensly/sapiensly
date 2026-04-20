<script setup lang="ts">
import * as KnowledgeBaseController from '@/actions/App/Http/Controllers/KnowledgeBaseController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { PaginatedKnowledgeBases } from '@/types/knowledge-base';
import { Head, Link } from '@inertiajs/vue3';
import { Database, FileText, Plus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    knowledgeBases: PaginatedKnowledgeBases;
}

defineProps<Props>();

const statusTint: Record<string, string> = {
    ready: 'var(--sp-success)',
    processing: 'var(--sp-accent-blue)',
    pending: 'var(--sp-text-secondary)',
    failed: 'var(--sp-danger)',
};

function tintFor(status: string) {
    return statusTint[status] ?? 'var(--sp-text-secondary)';
}
</script>

<template>
    <Head :title="t('knowledge_bases.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.knowledge_base')">
        <div class="space-y-6">
            <PageHeader
                :title="t('knowledge_bases.index.heading')"
                :description="t('knowledge_bases.index.description')"
            >
                <template #actions>
                    <Link :href="KnowledgeBaseController.create().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('knowledge_bases.index.new_kb') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div
                v-if="knowledgeBases.data.length === 0"
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <Database class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ t('knowledge_bases.index.no_kbs') }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ t('knowledge_bases.index.no_kbs_description') }}
                </p>
                <Link :href="KnowledgeBaseController.create().url" class="mt-4 inline-block">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('knowledge_bases.index.create_kb') }}
                    </button>
                </Link>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="kb in knowledgeBases.data"
                    :key="kb.id"
                    :href="KnowledgeBaseController.show({ knowledge_base: kb.id }).url"
                    class="flex flex-col rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
                            >
                                <Database class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-ink">
                                    {{ kb.name }}
                                </h3>
                                <p
                                    v-if="kb.description"
                                    class="mt-0.5 line-clamp-2 text-xs text-ink-muted"
                                >
                                    {{ kb.description }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: tintFor(kb.status),
                                borderColor: `color-mix(in oklab, ${tintFor(kb.status)} 45%, transparent)`,
                            }"
                        >
                            {{ kb.status }}
                        </span>
                    </div>

                    <div
                        class="mt-4 flex items-center gap-3 border-t border-soft pt-3 text-[11px] text-ink-subtle"
                    >
                        <span class="inline-flex items-center gap-1">
                            <FileText class="size-3" />
                            {{ kb.total_documents_count ?? 0 }}
                            {{ t('knowledge_bases.index.documents') }}
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayoutV2>
</template>
