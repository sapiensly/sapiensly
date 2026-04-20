<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { PaginatedTools, ToolType, ToolTypeOption } from '@/types/tools';
import { Head, Link, router } from '@inertiajs/vue3';
import { Code, Layers, Plus, Server, Wrench } from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    tools: PaginatedTools;
    toolsByType: Record<ToolType, number>;
    currentType: ToolType | null;
    toolTypes: ToolTypeOption[];
}

const props = defineProps<Props>();

function toolIcon(type: ToolType): Component {
    switch (type) {
        case 'function':
            return Code;
        case 'mcp':
            return Server;
        case 'group':
            return Layers;
        default:
            return Wrench;
    }
}

const statusTint: Record<string, string> = {
    active: 'var(--sp-success)',
    inactive: 'var(--sp-text-secondary)',
    draft: 'var(--sp-accent-blue)',
};

function tintFor(status: string) {
    return statusTint[status] ?? 'var(--sp-text-secondary)';
}

function filterByType(type: string | null) {
    router.get(ToolController.index().url, type ? { type } : {}, {
        preserveState: true,
    });
}

const totalTools = computed(() =>
    Object.values(props.toolsByType).reduce((sum, count) => sum + count, 0),
);
</script>

<template>
    <Head :title="t('tools.index.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.tools')">
        <div class="space-y-6">
            <PageHeader
                :title="t('tools.index.heading')"
                :description="t('tools.index.description')"
            >
                <template #actions>
                    <Link :href="ToolController.create().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('tools.index.new_tool') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-pill border px-3 py-1 text-xs transition-colors',
                        !currentType
                            ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                            : 'border-medium bg-white/5 text-ink-muted hover:text-ink',
                    ]"
                    @click="filterByType(null)"
                >
                    {{ t('common.all') }}
                    <span class="text-ink-subtle">({{ totalTools }})</span>
                </button>
                <button
                    v-for="type in toolTypes"
                    :key="type.value"
                    type="button"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-pill border px-3 py-1 text-xs transition-colors',
                        currentType === type.value
                            ? 'border-accent-blue/40 bg-accent-blue/10 text-ink'
                            : 'border-medium bg-white/5 text-ink-muted hover:text-ink',
                    ]"
                    @click="filterByType(type.value)"
                >
                    <component :is="toolIcon(type.value)" class="size-3" />
                    {{ type.label }}
                    <span class="text-ink-subtle">({{ toolsByType[type.value] ?? 0 }})</span>
                </button>
            </div>

            <div
                v-if="tools.data.length === 0"
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <Wrench class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ currentType ? t('tools.index.no_tools_filtered') : t('tools.index.no_tools') }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ currentType ? t('tools.index.no_tools_type') : t('tools.index.no_tools_description') }}
                </p>
                <Link
                    :href="currentType ? `${ToolController.create().url}?type=${currentType}` : ToolController.create().url"
                    class="mt-4 inline-block"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('tools.index.create_tool') }}
                    </button>
                </Link>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="tool in tools.data"
                    :key="tool.id"
                    :href="ToolController.show({ tool: tool.id }).url"
                    class="flex flex-col rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
                            >
                                <component :is="toolIcon(tool.type)" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-ink">
                                    {{ tool.name }}
                                </h3>
                                <p
                                    v-if="tool.description"
                                    class="mt-0.5 line-clamp-2 text-xs text-ink-muted"
                                >
                                    {{ tool.description }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                            :style="{
                                color: tintFor(tool.status),
                                borderColor: `color-mix(in oklab, ${tintFor(tool.status)} 45%, transparent)`,
                            }"
                        >
                            {{ tool.status }}
                        </span>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap items-center gap-3 border-t border-soft pt-3 text-[11px] text-ink-subtle"
                    >
                        <span
                            class="inline-flex items-center rounded-pill border border-medium px-2 py-0.5 text-[10px] capitalize"
                        >
                            {{ tool.type }}
                        </span>
                        <span
                            v-if="tool.is_validated"
                            class="inline-flex items-center gap-1 text-sp-success"
                        >
                            {{ t('tools.index.validated') }}
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayoutV2>
</template>
