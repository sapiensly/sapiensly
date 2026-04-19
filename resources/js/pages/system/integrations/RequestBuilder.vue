<script setup lang="ts">
import HeaderEditor from '@/components/integrations/HeaderEditor.vue';
import JsonViewer from '@/components/integrations/JsonViewer.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { Loader2, Play, Save } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface KvPair {
    key: string;
    value: string;
    enabled?: boolean;
}

interface RequestPayload {
    id: string;
    integration_id: string;
    name: string;
    description: string | null;
    folder: string | null;
    method: string;
    path: string;
    query_params: KvPair[];
    headers: KvPair[];
    body_type: string | null;
    body_content: string | null;
    timeout_ms: number;
    follow_redirects: boolean;
    sort_order: number;
}

interface VariableMeta {
    key: string;
    is_secret: boolean;
}

interface EnvironmentMeta {
    id: string;
    name: string;
    variables: VariableMeta[];
}

interface Integration {
    id: string;
    name: string;
    base_url: string;
    active_environment_id: string | null;
    environments: EnvironmentMeta[];
}

interface Props {
    request: RequestPayload;
    integration: Integration;
}

const props = defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('nav.system'), href: '#' },
    { title: t('system.integrations.title'), href: '/system/integrations' },
    { title: props.integration.name, href: `/system/integrations/${props.integration.id}` },
    { title: props.request.name, href: '#' },
]);

const form = useForm({
    name: props.request.name,
    method: props.request.method,
    path: props.request.path,
    query_params: props.request.query_params ?? [],
    headers: props.request.headers ?? [],
    body_type: props.request.body_type ?? 'none',
    body_content: props.request.body_content ?? '',
    timeout_ms: props.request.timeout_ms,
    follow_redirects: props.request.follow_redirects,
});

function save(): void {
    form.put(`/system/integrations/requests/${props.request.id}`, { preserveScroll: true });
}

// ============== Execute ==============
type ExecResult = {
    success: boolean;
    status: number | null;
    duration_ms: number;
    content_type: string | null;
    response_headers: Record<string, string | string[]> | null;
    response_body: string | null;
    response_size_bytes: number | null;
    response_truncated: boolean;
    error: string | null;
    execution_id: string | null;
} | null;

const sending = ref(false);
const result = ref<ExecResult>(null);
const runtimeVars = ref<Record<string, string>>({});
const responseTab = ref<'body' | 'headers'>('body');

const allVariables = computed<VariableMeta[]>(() => {
    const activeEnv = props.integration.environments.find(
        (e) => e.id === props.integration.active_environment_id,
    );
    return activeEnv?.variables ?? [];
});

async function send(): Promise<void> {
    sending.value = true;
    result.value = null;
    try {
        const { data } = await axios.post(
            `/system/integrations/requests/${props.request.id}/execute`,
            { variables: runtimeVars.value },
        );
        result.value = data;
    } catch (e: any) {
        result.value = {
            success: false,
            status: e.response?.status ?? null,
            duration_ms: 0,
            content_type: null,
            response_headers: null,
            response_body: e.response?.data ? JSON.stringify(e.response.data) : null,
            response_size_bytes: null,
            response_truncated: false,
            error: e.response?.data?.message ?? String(e),
            execution_id: null,
        };
    } finally {
        sending.value = false;
    }
}

onMounted(() => {
    const handler = (event: KeyboardEvent) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            send();
        }
    };
    window.addEventListener('keydown', handler);
});

function statusBadgeVariant(status: number | null): 'default' | 'secondary' | 'destructive' {
    if (status === null) return 'destructive';
    if (status >= 500) return 'destructive';
    if (status >= 400) return 'destructive';
    if (status >= 300) return 'secondary';
    return 'default';
}

function variableToken(key: string): string {
    return '{{' + key + '}}';
}

function insertVariable(key: string): void {
    form.path = form.path + variableToken(key);
}
</script>

<template>
    <Head :title="request.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-7xl">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <Heading
                        :title="request.name"
                        :description="integration.base_url"
                    />
                    <div class="flex items-center gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="`/system/integrations/${integration.id}`">
                                {{ t('common.back') }}
                            </Link>
                        </Button>
                        <Button variant="outline" @click="save" :disabled="form.processing">
                            <Save class="mr-2 h-4 w-4" />
                            {{ t('common.save') }}
                        </Button>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- LEFT: request editor -->
                    <Card>
                        <CardContent class="space-y-4 pt-6">
                            <div class="flex items-center gap-2">
                                <Select v-model="form.method">
                                    <SelectTrigger class="w-28">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="m in ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS']" :key="m" :value="m">
                                            {{ m }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input
                                    v-model="form.path"
                                    placeholder="/users/{{id}}"
                                    class="flex-1 font-mono"
                                />
                                <Button @click="send" :disabled="sending" class="shrink-0">
                                    <Loader2 v-if="sending" class="mr-2 h-4 w-4 animate-spin" />
                                    <Play v-else class="mr-2 h-4 w-4" />
                                    Send
                                </Button>
                            </div>

                            <Tabs default-value="params">
                                <TabsList>
                                    <TabsTrigger value="params">Params</TabsTrigger>
                                    <TabsTrigger value="headers">Headers</TabsTrigger>
                                    <TabsTrigger value="body">Body</TabsTrigger>
                                    <TabsTrigger value="variables">Variables</TabsTrigger>
                                </TabsList>

                                <TabsContent value="params" class="mt-3">
                                    <HeaderEditor v-model="form.query_params" show-enabled />
                                </TabsContent>

                                <TabsContent value="headers" class="mt-3">
                                    <HeaderEditor v-model="form.headers" show-enabled />
                                </TabsContent>

                                <TabsContent value="body" class="mt-3 space-y-2">
                                    <Select v-model="form.body_type">
                                        <SelectTrigger class="w-48">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">None</SelectItem>
                                            <SelectItem value="json">JSON</SelectItem>
                                            <SelectItem value="raw">Raw</SelectItem>
                                            <SelectItem value="form_urlencoded">Form URL-encoded</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Textarea
                                        v-if="form.body_type && form.body_type !== 'none'"
                                        v-model="form.body_content"
                                        rows="12"
                                        class="font-mono text-xs"
                                    />
                                </TabsContent>

                                <TabsContent value="variables" class="mt-3 space-y-2">
                                    <p class="text-xs text-muted-foreground">
                                        Override active environment variables just for this test run.
                                    </p>
                                    <div
                                        v-for="variable in allVariables"
                                        :key="variable.key"
                                        class="flex items-center gap-2"
                                    >
                                        <code class="w-40 font-mono text-xs">{{ variableToken(variable.key) }}</code>
                                        <Input
                                            v-model="runtimeVars[variable.key]"
                                            :placeholder="variable.is_secret ? '••••' : ''"
                                            class="flex-1"
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            @click="insertVariable(variable.key)"
                                        >
                                            Insert
                                        </Button>
                                    </div>
                                    <p
                                        v-if="allVariables.length === 0"
                                        class="text-xs text-muted-foreground"
                                    >
                                        No variables in the active environment.
                                    </p>
                                </TabsContent>
                            </Tabs>
                        </CardContent>
                    </Card>

                    <!-- RIGHT: response viewer -->
                    <Card>
                        <CardContent class="space-y-3 pt-6">
                            <div v-if="!result" class="py-16 text-center text-sm text-muted-foreground">
                                Press Send (or Cmd+Enter) to execute this request.
                            </div>

                            <template v-else>
                                <div class="flex items-center gap-2">
                                    <Badge :variant="statusBadgeVariant(result.status)">
                                        {{ result.status ?? 'ERR' }}
                                    </Badge>
                                    <span class="text-xs text-muted-foreground">
                                        {{ result.duration_ms }} ms
                                    </span>
                                    <span
                                        v-if="result.response_size_bytes !== null"
                                        class="text-xs text-muted-foreground"
                                    >
                                        · {{ Math.round((result.response_size_bytes ?? 0) / 1024) }} KB
                                    </span>
                                    <Badge
                                        v-if="result.response_truncated"
                                        variant="outline"
                                        class="ml-auto text-[10px]"
                                    >
                                        truncated
                                    </Badge>
                                </div>

                                <div
                                    v-if="result.error"
                                    class="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
                                >
                                    {{ result.error }}
                                </div>

                                <Tabs v-model="responseTab">
                                    <TabsList>
                                        <TabsTrigger value="body">Body</TabsTrigger>
                                        <TabsTrigger value="headers">Headers</TabsTrigger>
                                    </TabsList>

                                    <TabsContent value="body" class="mt-3">
                                        <JsonViewer
                                            :value="result.response_body"
                                            :content-type="result.content_type"
                                        />
                                    </TabsContent>

                                    <TabsContent value="headers" class="mt-3">
                                        <pre
                                            class="max-h-[400px] overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs leading-5"
                                        >{{ result.response_headers ? JSON.stringify(result.response_headers, null, 2) : '—' }}</pre>
                                    </TabsContent>
                                </Tabs>
                            </template>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
