<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link } from '@inertiajs/vue3';
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

defineProps<Props>();

const { t } = useI18n();
</script>

<template>
    <Head :title="`${integration.name} — ${t('system.integrations.tabs.executions')}`" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                :title="t('system.integrations.tabs.executions')"
                :description="integration.name"
            >
                <template #actions>
                    <Link
                        :href="`/system/integrations/${integration.id}`"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    >
                        {{ t('common.back') }}
                    </Link>
                </template>
            </PageHeader>

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
    </AppLayoutV2>
</template>
