<script setup lang="ts">
import PageHeader from '@/components/app-v2/PageHeader.vue';
import JsonViewer from '@/components/integrations/JsonViewer.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

interface Execution {
    id: string;
    integration_id: string;
    integration_request_id: string | null;
    method: string;
    url: string;
    request_headers: Record<string, string> | null;
    request_body: string | null;
    response_status: number | null;
    response_headers: Record<string, string | string[]> | null;
    response_body: string | null;
    response_size_bytes: number | null;
    duration_ms: number | null;
    success: boolean;
    error_message: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}

defineProps<{ execution: Execution }>();

const { t } = useI18n();
</script>

<template>
    <Head :title="`Execution ${execution.id}`" />

    <AppLayoutV2 :title="t('app_v2.nav.integrations')">
        <div class="mx-auto max-w-4xl space-y-6">
            <PageHeader title="Execution" :description="execution.id">
                <template #actions>
                    <Link
                        :href="`/system/integrations/${execution.integration_id}/executions`"
                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-white/5 px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-white/10"
                    >
                        {{ t('common.back') }}
                    </Link>
                </template>
            </PageHeader>

                <Card class="mb-4">
                    <CardContent class="space-y-3 pt-6">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="execution.success ? 'default' : 'destructive'">
                                {{ execution.response_status ?? 'ERR' }}
                            </Badge>
                            <Badge variant="outline">{{ execution.method }}</Badge>
                            <span class="text-xs text-muted-foreground">
                                {{ execution.duration_ms }} ms
                            </span>
                            <span v-if="execution.response_size_bytes !== null" class="text-xs text-muted-foreground">
                                · {{ Math.round((execution.response_size_bytes ?? 0) / 1024) }} KB
                            </span>
                            <span class="ml-auto text-xs text-muted-foreground">
                                {{ execution.created_at }}
                            </span>
                        </div>
                        <p class="break-all font-mono text-xs">{{ execution.url }}</p>
                        <div
                            v-if="execution.error_message"
                            class="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                        >
                            {{ execution.error_message }}
                        </div>
                    </CardContent>
                </Card>

                <Tabs default-value="response">
                    <TabsList>
                        <TabsTrigger value="response">Response</TabsTrigger>
                        <TabsTrigger value="request">Request</TabsTrigger>
                        <TabsTrigger value="metadata">Metadata</TabsTrigger>
                    </TabsList>

                    <TabsContent value="response" class="mt-3 space-y-3">
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                                Body
                            </h4>
                            <JsonViewer
                                :value="execution.response_body"
                                :content-type="(execution.response_headers?.['content-type'] as string) ?? null"
                            />
                        </div>
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                                Headers
                            </h4>
                            <pre
                                class="max-h-[200px] overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs leading-5"
                            >{{ execution.response_headers ? JSON.stringify(execution.response_headers, null, 2) : '—' }}</pre>
                        </div>
                    </TabsContent>

                    <TabsContent value="request" class="mt-3 space-y-3">
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                                Headers (redacted)
                            </h4>
                            <pre
                                class="max-h-[200px] overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs leading-5"
                            >{{ execution.request_headers ? JSON.stringify(execution.request_headers, null, 2) : '—' }}</pre>
                        </div>
                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                                Body
                            </h4>
                            <JsonViewer :value="execution.request_body" />
                        </div>
                    </TabsContent>

                    <TabsContent value="metadata" class="mt-3">
                        <pre
                            class="max-h-[400px] overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs leading-5"
                        >{{ execution.metadata ? JSON.stringify(execution.metadata, null, 2) : '—' }}</pre>
                    </TabsContent>
                </Tabs>
        </div>
    </AppLayoutV2>
</template>
