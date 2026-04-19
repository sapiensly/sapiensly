<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface ExecutionRow {
    id: string;
    method: string;
    url: string;
    status: number | null;
    success: boolean;
    duration_ms: number | null;
    created_at: string | null;
    integration_request_id: string | null;
}

interface Props {
    integration: { id: string; name: string };
    executions: ExecutionRow[];
    filters: { status: string | null; request_id: string | null };
}

const props = defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    { title: t('system.integrations.title'), href: '/system/integrations' },
    { title: props.integration.name, href: `/system/integrations/${props.integration.id}` },
    { title: t('system.integrations.tabs.executions'), href: '#' },
]);
</script>

<template>
    <Head :title="`${integration.name} — ${t('system.integrations.tabs.executions')}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-5xl">
                <div class="mb-6 flex items-start justify-between gap-4">
                    <Heading
                        :title="t('system.integrations.tabs.executions')"
                        :description="integration.name"
                    />
                    <Button variant="outline" as-child>
                        <Link :href="`/system/integrations/${integration.id}`">
                            {{ t('common.back') }}
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardContent class="pt-6">
                        <p
                            v-if="executions.length === 0"
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            {{ t('common.no_results', { default: 'No executions yet' }) }}
                        </p>

                        <div v-else class="divide-y rounded-md border">
                            <Link
                                v-for="exec in executions"
                                :key="exec.id"
                                :href="`/system/integrations/executions/${exec.id}`"
                                class="flex items-center gap-3 px-4 py-3 transition hover:bg-muted/50"
                            >
                                <Badge
                                    :variant="exec.success ? 'default' : 'destructive'"
                                    class="w-12 justify-center text-xs"
                                >
                                    {{ exec.status ?? 'ERR' }}
                                </Badge>
                                <Badge variant="outline" class="w-16 justify-center text-xs">
                                    {{ exec.method }}
                                </Badge>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-xs">{{ exec.url }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ exec.created_at }}
                                        <span v-if="exec.duration_ms !== null">· {{ exec.duration_ms }} ms</span>
                                    </p>
                                </div>
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
