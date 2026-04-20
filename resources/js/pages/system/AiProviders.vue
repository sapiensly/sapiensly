<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    Database,
    Loader2,
    Pencil,
    Plug,
    Plus,
    Star,
    Trash2,
    X,
} from 'lucide-vue-next';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Provider {
    id: string;
    name: string;
    driver: string;
    display_name: string;
    models_count: number;
    chat_models_count: number;
    embedding_models_count: number;
    is_default: boolean;
    is_default_embeddings: boolean;
    status: string;
    created_at: string;
}

defineProps<{
    providers: Provider[];
    configuredDrivers: string[];
}>();

function setDefault(id: string) {
    router.post(`/system/ai-providers/${id}/set-default`);
}

function setDefaultEmbeddings(id: string) {
    router.post(`/system/ai-providers/${id}/set-default-embeddings`);
}

const connectionTests = reactive<
    Record<
        string,
        {
            loading: boolean;
            result: null | {
                success: boolean;
                message: string;
                detail?: string;
            };
        }
    >
>({});

async function testConnection(id: string) {
    connectionTests[id] = { loading: true, result: null };

    try {
        const response = await fetch(`/system/ai-providers/${id}/test-connection`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
                Accept: 'application/json',
            },
        });

        const data = await response.json();
        connectionTests[id] = { loading: false, result: data };
    } catch {
        connectionTests[id] = {
            loading: false,
            result: {
                success: false,
                message: t('system.ai_providers.connection_failed'),
            },
        };
    }

    const delay = connectionTests[id]?.result?.success ? 5000 : 15000;
    setTimeout(() => {
        if (connectionTests[id]) {
            connectionTests[id].result = null;
        }
    }, delay);
}

function deleteProvider(id: string) {
    if (confirm('Are you sure you want to remove this provider?')) {
        router.delete(`/system/ai-providers/${id}`);
    }
}
</script>

<template>
    <Head :title="t('system.ai_providers.title')" />

    <AppLayoutV2 :title="t('app_v2.nav.ai_providers')">
        <div class="space-y-6">
            <PageHeader
                :title="t('system.ai_providers.title')"
                :description="t('system.ai_providers.description')"
            >
                <template #actions>
                    <Link href="/system/ai-providers/create">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                        >
                            <Plus class="size-3.5" />
                            {{ t('system.ai_providers.add') }}
                        </button>
                    </Link>
                </template>
            </PageHeader>

            <div
                v-if="providers.length === 0"
                class="rounded-sp-sm border border-dashed border-soft bg-navy/40 px-6 py-12 text-center"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-xs bg-white/5 text-ink-muted"
                >
                    <Database class="size-5" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-ink">
                    {{ t('system.ai_providers.no_providers') }}
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ t('system.ai_providers.no_providers_description') }}
                </p>
                <Link href="/system/ai-providers/create" class="mt-4 inline-block">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-pill bg-accent-blue px-3.5 py-1.5 text-xs font-medium text-white shadow-btn-primary transition-colors hover:bg-accent-blue-hover"
                    >
                        <Plus class="size-3.5" />
                        {{ t('system.ai_providers.add_first') }}
                    </button>
                </Link>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="provider in providers"
                    :key="provider.id"
                    :class="[
                        'flex flex-col rounded-sp-sm border border-soft bg-navy p-5 transition-colors hover:border-accent-blue/30',
                        provider.status === 'inactive' ? 'opacity-60' : '',
                    ]"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-xs bg-accent-blue/10 text-accent-blue"
                            >
                                <Plug class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-ink">
                                    {{ provider.display_name }}
                                </h3>
                                <p class="mt-0.5 text-[11px] text-ink-subtle">
                                    {{ provider.chat_models_count }}
                                    {{ t('system.ai_providers.chat_models') }}
                                    <span v-if="provider.embedding_models_count > 0">
                                        · {{ provider.embedding_models_count }}
                                        {{ t('system.ai_providers.embedding_models') }}
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-wrap justify-end gap-1">
                            <span
                                v-if="provider.is_default"
                                class="inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                style="color: var(--sp-accent-blue); border-color: color-mix(in oklab, var(--sp-accent-blue) 45%, transparent)"
                            >
                                {{ t('system.ai_providers.default') }}
                            </span>
                            <span
                                v-if="provider.is_default_embeddings"
                                class="inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase"
                                style="color: var(--sp-spectrum-magenta); border-color: color-mix(in oklab, var(--sp-spectrum-magenta) 45%, transparent)"
                            >
                                {{ t('system.ai_providers.embeddings') }}
                            </span>
                            <span
                                v-if="provider.status === 'inactive'"
                                class="inline-flex items-center rounded-pill border border-medium px-2 py-0.5 text-[10px] font-semibold tracking-wider text-ink-muted uppercase"
                            >
                                Inactive
                            </span>
                        </div>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap items-center gap-1 border-t border-soft pt-3"
                    >
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-7 text-ink-muted hover:bg-white/5 hover:text-ink"
                                        :disabled="connectionTests[provider.id]?.loading"
                                        @click="testConnection(provider.id)"
                                    >
                                        <Loader2
                                            v-if="connectionTests[provider.id]?.loading"
                                            class="size-3.5 animate-spin"
                                        />
                                        <Check
                                            v-else-if="connectionTests[provider.id]?.result?.success"
                                            class="size-3.5 text-sp-success"
                                        />
                                        <X
                                            v-else-if="connectionTests[provider.id]?.result && !connectionTests[provider.id]?.result?.success"
                                            class="size-3.5 text-sp-danger"
                                        />
                                        <Plug v-else class="size-3.5" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ t('system.ai_providers.test_connection') }}
                                </TooltipContent>
                            </Tooltip>
                            <Tooltip v-if="!provider.is_default">
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-7 text-ink-muted hover:bg-white/5 hover:text-ink"
                                        @click="setDefault(provider.id)"
                                    >
                                        <Star class="size-3.5" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ t('system.ai_providers.set_default') }}
                                </TooltipContent>
                            </Tooltip>
                            <Tooltip v-if="!provider.is_default_embeddings && provider.embedding_models_count > 0">
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-7 text-ink-muted hover:bg-white/5 hover:text-ink"
                                        @click="setDefaultEmbeddings(provider.id)"
                                    >
                                        <Database class="size-3.5" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ t('system.ai_providers.set_embeddings') }}
                                </TooltipContent>
                            </Tooltip>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-7 text-ink-muted hover:bg-white/5 hover:text-ink"
                                        as-child
                                    >
                                        <Link :href="`/system/ai-providers/${provider.id}/edit`">
                                            <Pencil class="size-3.5" />
                                        </Link>
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ t('common.edit') }}
                                </TooltipContent>
                            </Tooltip>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-7 text-sp-danger hover:bg-sp-danger/10 hover:text-sp-danger"
                                        @click="deleteProvider(provider.id)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ t('common.delete') }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>

                    <div
                        v-if="connectionTests[provider.id]?.result?.detail"
                        :class="[
                            'mt-3 rounded-xs border px-3 py-2 text-[11px]',
                            connectionTests[provider.id]?.result?.success
                                ? 'border-sp-success/30 bg-sp-success/10 text-sp-success'
                                : 'border-sp-danger/30 bg-sp-danger/10 text-sp-danger',
                        ]"
                    >
                        {{ connectionTests[provider.id].result.detail }}
                    </div>
                </div>
            </div>
        </div>
    </AppLayoutV2>
</template>
