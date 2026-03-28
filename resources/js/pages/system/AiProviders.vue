<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
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
import { computed, reactive } from 'vue';
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    { title: t('system.ai_providers.title'), href: '/system/ai-providers' },
]);

const setDefault = (id: string) => {
    router.post(`/system/ai-providers/${id}/set-default`);
};

const setDefaultEmbeddings = (id: string) => {
    router.post(`/system/ai-providers/${id}/set-default-embeddings`);
};

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

const testConnection = async (id: string) => {
    connectionTests[id] = { loading: true, result: null };

    try {
        const response = await fetch(
            `/system/ai-providers/${id}/test-connection`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                    ),
                    Accept: 'application/json',
                },
            },
        );

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
};

const deleteProvider = (id: string) => {
    if (confirm('Are you sure you want to remove this provider?')) {
        router.delete(`/system/ai-providers/${id}`);
    }
};
</script>

<template>
    <Head :title="t('system.ai_providers.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
        >
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        {{ t('system.ai_providers.title') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ t('system.ai_providers.description') }}
                    </p>
                </div>
                <Button as-child>
                    <Link href="/system/ai-providers/create">
                        <Plus class="mr-2 h-4 w-4" />
                        {{ t('system.ai_providers.add') }}
                    </Link>
                </Button>
            </div>

            <div
                v-if="providers.length === 0"
                class="flex flex-col items-center justify-center rounded-lg border border-dashed p-12"
            >
                <Database class="mb-4 h-12 w-12 text-muted-foreground" />
                <h3 class="mb-2 text-lg font-medium">
                    {{ t('system.ai_providers.no_providers') }}
                </h3>
                <p class="mb-4 text-center text-sm text-muted-foreground">
                    {{ t('system.ai_providers.no_providers_description') }}
                </p>
                <Button as-child>
                    <Link href="/system/ai-providers/create">
                        <Plus class="mr-2 h-4 w-4" />
                        {{ t('system.ai_providers.add_first') }}
                    </Link>
                </Button>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card
                    v-for="provider in providers"
                    :key="provider.id"
                    :class="{ 'opacity-60': provider.status === 'inactive' }"
                >
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm font-medium">
                                {{ provider.display_name }}
                            </CardTitle>
                            <div class="flex gap-1.5">
                                <Badge
                                    v-if="provider.is_default"
                                    variant="default"
                                    class="text-xs"
                                >
                                    {{ t('system.ai_providers.default') }}
                                </Badge>
                                <Badge
                                    v-if="provider.is_default_embeddings"
                                    variant="secondary"
                                    class="text-xs"
                                >
                                    {{ t('system.ai_providers.embeddings') }}
                                </Badge>
                                <Badge
                                    v-if="provider.status === 'inactive'"
                                    variant="outline"
                                    class="text-xs"
                                >
                                    Inactive
                                </Badge>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div
                                class="flex items-center gap-4 text-sm text-muted-foreground"
                            >
                                <span
                                    >{{ provider.chat_models_count }}
                                    {{
                                        t('system.ai_providers.chat_models')
                                    }}</span
                                >
                                <span
                                    v-if="provider.embedding_models_count > 0"
                                >
                                    {{ provider.embedding_models_count }}
                                    {{
                                        t(
                                            'system.ai_providers.embedding_models',
                                        )
                                    }}
                                </span>
                            </div>

                            <TooltipProvider>
                                <div class="flex flex-wrap items-center gap-1">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7"
                                                :disabled="
                                                    connectionTests[provider.id]
                                                        ?.loading
                                                "
                                                @click="
                                                    testConnection(provider.id)
                                                "
                                            >
                                                <Loader2
                                                    v-if="
                                                        connectionTests[
                                                            provider.id
                                                        ]?.loading
                                                    "
                                                    class="h-3.5 w-3.5 animate-spin"
                                                />
                                                <Check
                                                    v-else-if="
                                                        connectionTests[
                                                            provider.id
                                                        ]?.result?.success
                                                    "
                                                    class="h-3.5 w-3.5 text-green-600"
                                                />
                                                <X
                                                    v-else-if="
                                                        connectionTests[
                                                            provider.id
                                                        ]?.result &&
                                                        !connectionTests[
                                                            provider.id
                                                        ]?.result?.success
                                                    "
                                                    class="h-3.5 w-3.5 text-destructive"
                                                />
                                                <Plug
                                                    v-else
                                                    class="h-3.5 w-3.5"
                                                />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>{{
                                            t(
                                                'system.ai_providers.test_connection',
                                            )
                                        }}</TooltipContent>
                                    </Tooltip>
                                    <Tooltip v-if="!provider.is_default">
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7"
                                                @click="setDefault(provider.id)"
                                            >
                                                <Star class="h-3.5 w-3.5" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>{{
                                            t('system.ai_providers.set_default')
                                        }}</TooltipContent>
                                    </Tooltip>
                                    <Tooltip
                                        v-if="
                                            !provider.is_default_embeddings &&
                                            provider.embedding_models_count > 0
                                        "
                                    >
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7"
                                                @click="
                                                    setDefaultEmbeddings(
                                                        provider.id,
                                                    )
                                                "
                                            >
                                                <Database class="h-3.5 w-3.5" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>{{
                                            t(
                                                'system.ai_providers.set_embeddings',
                                            )
                                        }}</TooltipContent>
                                    </Tooltip>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7"
                                                as-child
                                            >
                                                <Link
                                                    :href="`/system/ai-providers/${provider.id}/edit`"
                                                >
                                                    <Pencil
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </Link>
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>{{
                                            t('common.edit')
                                        }}</TooltipContent>
                                    </Tooltip>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7 text-destructive hover:text-destructive"
                                                @click="
                                                    deleteProvider(provider.id)
                                                "
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>{{
                                            t('common.delete')
                                        }}</TooltipContent>
                                    </Tooltip>
                                </div>
                            </TooltipProvider>

                            <div
                                v-if="
                                    connectionTests[provider.id]?.result?.detail
                                "
                                class="rounded-md border px-3 py-2 text-xs"
                                :class="
                                    connectionTests[provider.id]?.result
                                        ?.success
                                        ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300'
                                        : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300'
                                "
                            >
                                {{ connectionTests[provider.id].result.detail }}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
