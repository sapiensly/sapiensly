<script setup lang="ts">
import * as FlowController from '@/actions/App/Http/Controllers/FlowController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Bot, GitBranch, Pencil, Plus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface FlowItem {
    id: string;
    name: string;
    description: string | null;
    status: string;
    version: number;
    updated_at: string;
    agent_id: string;
    agent: {
        id: string;
        name: string;
        type: string;
    } | null;
}

interface Pagination {
    data: FlowItem[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    flows: Pagination;
}

defineProps<Props>();

const statusTint: Record<string, string> = {
    active: 'var(--sp-success)',
    inactive: 'var(--sp-text-secondary)',
    draft: 'var(--sp-accent-blue)',
};

function tintFor(status: string) {
    return statusTint[status] ?? 'var(--sp-text-secondary)';
}

const formatDate = (dateString: string) =>
    new Date(dateString).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <Head :title="t('app_v2.nav.flows')" />

    <AppLayoutV2 :title="t('app_v2.nav.flows')">
        <div class="space-y-6">
            <PageHeader
                :title="t('app_v2.flows.heading')"
                :description="t('app_v2.flows.description')"
            >
                <template #actions>
                    <Link :href="FlowController.globalCreate().url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('flows.global.create_flow') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div v-if="flows.data.length > 0" class="space-y-3">
                <div
                    v-for="flow in flows.data"
                    :key="flow.id"
                    class="rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
                            >
                                <GitBranch class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-ink">
                                    {{ flow.name }}
                                </h3>
                                <p
                                    v-if="flow.description"
                                    class="mt-0.5 text-xs text-ink-muted"
                                >
                                    {{ flow.description }}
                                </p>
                                <div
                                    class="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-ink-subtle"
                                >
                                    <span
                                        v-if="flow.agent"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Bot class="size-3" />
                                        {{ flow.agent.name }}
                                    </span>
                                    <span>v{{ flow.version }}</span>
                                    <span>{{ formatDate(flow.updated_at) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-pill border px-2.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                :style="{
                                    color: tintFor(flow.status),
                                    borderColor: `color-mix(in oklab, ${tintFor(flow.status)} 45%, transparent)`,
                                }"
                            >
                                {{ t(`flows.status.${flow.status}`) }}
                            </span>
                            <Link
                                :href="
                                    flow.agent
                                        ? FlowController.edit({
                                              agent: flow.agent_id,
                                              flow: flow.id,
                                          }).url
                                        : FlowController.globalEdit({
                                              flow: flow.id,
                                          }).url
                                "
                                class="inline-flex items-center gap-1 rounded-xs px-2 py-1 text-xs text-ink-muted transition-colors hover:bg-white/5 hover:text-ink"
                            >
                                <Pencil class="size-3" />
                                {{ t('common.edit') }}
                            </Link>
                        </div>
                    </div>
                </div>

                <nav
                    v-if="flows.last_page > 1"
                    class="flex items-center justify-between pt-2 text-xs text-ink-muted"
                >
                    <span>
                        {{ t('common.page') }} {{ flows.current_page }}
                        {{ t('common.of') }} {{ flows.last_page }}
                    </span>
                    <div class="flex items-center gap-4">
                        <Link
                            v-if="flows.prev_page_url"
                            :href="flows.prev_page_url"
                            class="transition-colors hover:text-ink"
                        >
                            {{ t('common.previous') }}
                        </Link>
                        <Link
                            v-if="flows.next_page_url"
                            :href="flows.next_page_url"
                            class="transition-colors hover:text-ink"
                        >
                            {{ t('common.next') }}
                        </Link>
                    </div>
                </nav>
            </div>

            <div
                v-else
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <GitBranch class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ t('flows.global.no_flows') }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ t('flows.global.no_flows_description') }}
                </p>
                <Link :href="FlowController.globalCreate().url" class="mt-4 inline-block">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('flows.global.create_flow') }}
                    </button>
                </Link>
            </div>
        </div>
    </AppLayoutV2>
</template>
