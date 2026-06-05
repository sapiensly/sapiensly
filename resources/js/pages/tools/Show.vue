<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { McpConfig, McpServerTool, Tool, ToolType } from '@/types/tools';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Braces,
    CheckCircle2,
    Code,
    Database,
    Globe,
    KeyRound,
    Layers,
    Pencil,
    RefreshCw,
    Search,
    Server,
    Trash2,
    Wrench,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface McpAuthorization {
    connected: boolean;
    authorize_url: string;
    integration_name: string;
}

interface Props {
    tool: Tool;
    mcpAuthorization?: McpAuthorization | null;
}

const props = withDefaults(defineProps<Props>(), { mcpAuthorization: null });

// MCP server tool catalog — the tools the connected server exposes.
const initialMcpConfig =
    props.tool.type === 'mcp' ? (props.tool.config as McpConfig | null) : null;
const mcpServerTools = ref<McpServerTool[]>(initialMcpConfig?.mcp_tools ?? []);
const mcpSyncedAt = ref<string | null>(initialMcpConfig?.mcp_tools_synced_at ?? null);
const refreshing = ref(false);
const refreshError = ref<string | null>(null);

async function reloadMcpTools(): Promise<void> {
    refreshing.value = true;
    refreshError.value = null;
    try {
        const { data } = await axios.post(`/tools/${props.tool.id}/mcp/refresh`);
        mcpServerTools.value = data.tools ?? [];
        mcpSyncedAt.value = data.synced_at ?? null;
    } catch (error) {
        refreshError.value =
            axios.isAxiosError(error) && error.response?.data?.message
                ? (error.response.data.message as string)
                : t('tools.show.mcp_reload_failed');
    } finally {
        refreshing.value = false;
    }
}

function requiredParams(tool: McpServerTool): string[] {
    const req = tool.input_schema?.required;
    return Array.isArray(req) ? (req as string[]) : [];
}

// Case-insensitive filter over tool name and description.
const mcpSearch = ref('');
const filteredMcpTools = computed<McpServerTool[]>(() => {
    const q = mcpSearch.value.trim().toLowerCase();
    if (!q) return mcpServerTools.value;
    return mcpServerTools.value.filter(
        (t) =>
            t.name.toLowerCase().includes(q) ||
            t.description.toLowerCase().includes(q),
    );
});

const toolIcon = (type: ToolType) => {
    switch (type) {
        case 'function':
            return Code;
        case 'mcp':
            return Server;
        case 'group':
            return Layers;
        case 'rest_api':
            return Globe;
        case 'graphql':
            return Braces;
        case 'database':
            return Database;
        default:
            return Wrench;
    }
};

const statusVariant = (status: string) => {
    switch (status) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
        default:
            return 'outline';
    }
};

const deleteTool = () => {
    router.delete(ToolController.destroy({ tool: props.tool.id }).url);
};

const functionConfig = computed(() => {
    if (props.tool.type !== 'function' || !props.tool.config) return null;
    return props.tool.config as {
        name?: string;
        description?: string;
        parameters?: Record<string, unknown>;
    };
});

const mcpConfig = computed(() => {
    if (props.tool.type !== 'mcp' || !props.tool.config) return null;
    return props.tool.config as {
        endpoint?: string;
        auth_type?: string;
    };
});

const restApiConfig = computed(() => {
    if (props.tool.type !== 'rest_api' || !props.tool.config) return null;
    return props.tool.config as {
        base_url?: string;
        method?: string;
        path?: string;
        auth_type?: string;
        auth_config_is_set?: boolean;
    };
});

const graphqlConfig = computed(() => {
    if (props.tool.type !== 'graphql' || !props.tool.config) return null;
    return props.tool.config as {
        endpoint?: string;
        operation_type?: string;
        operation?: string;
        auth_type?: string;
        auth_config_is_set?: boolean;
    };
});

const databaseConfig = computed(() => {
    if (props.tool.type !== 'database' || !props.tool.config) return null;
    return props.tool.config as {
        driver?: string;
        host?: string;
        port?: number;
        database?: string;
        username_is_set?: boolean;
        password_is_set?: boolean;
        query_template?: string;
        read_only?: boolean;
    };
});
</script>

<template>
    <Head :title="tool.name" />

    <AppLayoutV2 :title="t('app_v2.nav.tools')">
        <div class="mx-auto max-w-4xl space-y-6">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <component
                                :is="toolIcon(tool.type)"
                                class="h-6 w-6 text-ink-muted"
                            />
                            <h1 class="text-[22px] font-semibold leading-tight text-ink">{{ tool.name }}</h1>
                            <Badge :variant="statusVariant(tool.status)">
                                {{ tool.status }}
                            </Badge>
                        </div>
                        <p
                            v-if="tool.description"
                            class="text-xs text-ink-muted"
                        >
                            {{ tool.description }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    ToolController.edit({ tool: tool.id }).url
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
                                        t('tools.show.delete_tool')
                                    }}</DialogTitle>
                                    <DialogDescription>
                                        {{ t('common.confirm_delete') }} "{{
                                            tool.name
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
                                        @click="deleteTool"
                                    >
                                        {{ t('common.delete') }}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <div class="space-y-8">
                    <!-- Per-user MCP authorization. Authorization is specific
                         to each member, so it lives here on the tool. -->
                    <div
                        v-if="mcpAuthorization && !mcpAuthorization.connected"
                        class="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4"
                    >
                        <KeyRound class="mt-0.5 size-5 shrink-0 text-amber-500" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                {{ t('tools.show.authorize_title') }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ t('tools.show.authorize_hint') }}
                            </p>
                        </div>
                        <a
                            :href="mcpAuthorization.authorize_url"
                            class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-md bg-amber-500 px-3.5 py-1.5 text-xs font-medium text-white transition-colors hover:bg-amber-600"
                        >
                            <KeyRound class="size-3.5" />
                            {{ t('tools.show.authorize_cta') }}
                        </a>
                    </div>

                    <div
                        v-else-if="mcpAuthorization && mcpAuthorization.connected"
                        class="flex items-start gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-4"
                    >
                        <CheckCircle2 class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                {{ t('tools.show.connected_title') }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ t('tools.show.connected_hint') }}
                            </p>
                        </div>
                        <a
                            :href="mcpAuthorization.authorize_url"
                            class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-md border border-medium px-3 py-1.5 text-xs transition-colors hover:bg-surface-hover"
                        >
                            {{ t('tools.show.reauthorize_cta') }}
                        </a>
                    </div>

                    <!-- MCP server tool catalog. -->
                    <Card v-if="tool.type === 'mcp'">
                        <CardHeader>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <CardTitle>{{ t('tools.show.mcp_tools_title') }}</CardTitle>
                                    <CardDescription>
                                        {{ t('tools.show.mcp_tools_description') }}
                                        <span v-if="mcpSyncedAt" class="block text-xs">
                                            {{ t('tools.show.mcp_synced_at') }}
                                            {{ new Date(mcpSyncedAt).toLocaleString() }}
                                        </span>
                                    </CardDescription>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <Badge
                                        v-if="mcpServerTools.length > 0"
                                        variant="secondary"
                                    >
                                        {{ t('tools.show.mcp_tools_count', { count: mcpServerTools.length }) }}
                                    </Badge>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :disabled="refreshing"
                                        @click="reloadMcpTools"
                                    >
                                        <RefreshCw
                                            :class="['mr-1.5 size-3.5', refreshing ? 'animate-spin' : '']"
                                        />
                                        {{ t('tools.show.reload_tools') }}
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p
                                v-if="refreshError"
                                class="mb-3 rounded-md border border-sp-danger/30 bg-sp-danger/10 p-2 text-xs text-sp-danger"
                            >
                                {{ refreshError }}
                            </p>

                            <template v-if="mcpServerTools.length > 0">
                                <div class="relative mb-3">
                                    <Search
                                        class="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <Input
                                        v-model="mcpSearch"
                                        :placeholder="t('tools.show.mcp_search_placeholder')"
                                        class="h-9 pl-9"
                                    />
                                </div>

                                <div
                                    v-if="filteredMcpTools.length > 0"
                                    class="grid gap-3 sm:grid-cols-2"
                                >
                                    <div
                                        v-for="serverTool in filteredMcpTools"
                                        :key="serverTool.name"
                                        :title="serverTool.description"
                                        class="rounded-lg border border-medium p-3 transition-colors hover:border-strong hover:bg-surface-hover"
                                    >
                                        <div class="flex items-center gap-2">
                                            <Wrench class="size-3.5 shrink-0 text-muted-foreground" />
                                            <p class="truncate font-mono text-sm font-medium">
                                                {{ serverTool.name }}
                                            </p>
                                        </div>
                                        <p
                                            v-if="serverTool.description"
                                            class="mt-1 line-clamp-3 text-xs text-muted-foreground group-hover:line-clamp-none"
                                        >
                                            {{ serverTool.description }}
                                        </p>
                                        <div
                                            v-if="requiredParams(serverTool).length > 0"
                                            class="mt-2 flex flex-wrap gap-1"
                                        >
                                            <Badge
                                                v-for="param in requiredParams(serverTool)"
                                                :key="param"
                                                variant="secondary"
                                                class="font-mono text-[10px]"
                                            >
                                                {{ param }}
                                            </Badge>
                                        </div>
                                    </div>
                                </div>

                                <p
                                    v-else
                                    class="py-6 text-center text-sm text-muted-foreground"
                                >
                                    {{ t('tools.show.mcp_no_results') }}
                                </p>
                            </template>

                            <div
                                v-else
                                class="rounded-lg border border-dashed p-6 text-center"
                            >
                                <Server class="mx-auto size-6 text-muted-foreground" />
                                <p class="mt-2 text-sm text-muted-foreground">
                                    {{ t('tools.show.mcp_no_tools') }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{{
                                t('tools.show.configuration')
                            }}</CardTitle>
                            <CardDescription>
                                {{ t('tools.show.config_description') }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{ t('common.type') }}
                                    </dt>
                                    <dd class="mt-1 capitalize">
                                        {{ tool.type }}
                                    </dd>
                                </div>
                                <div v-if="mcpAuthorization">
                                    <dt
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{ t('tools.show.connection') }}
                                    </dt>
                                    <dd
                                        class="mt-1"
                                        :class="
                                            mcpAuthorization.connected
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-amber-600 dark:text-amber-400'
                                        "
                                    >
                                        {{
                                            mcpAuthorization.connected
                                                ? t('tools.show.connected')
                                                : t('tools.show.not_connected')
                                        }}
                                    </dd>
                                </div>
                                <div v-else>
                                    <dt
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{ t('tools.show.validated') }}
                                    </dt>
                                    <dd class="mt-1">
                                        {{
                                            tool.is_validated
                                                ? t('common.yes')
                                                : t('common.no')
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <div v-if="tool.type === 'function' && functionConfig">
                        <HeadingSmall
                            title="Function Definition"
                            description="The function schema for this tool"
                        />
                        <Card class="mt-4">
                            <CardContent class="pt-6">
                                <dl class="space-y-4">
                                    <div v-if="functionConfig.name">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Function Name
                                        </dt>
                                        <dd class="mt-1 font-mono">
                                            {{ functionConfig.name }}
                                        </dd>
                                    </div>
                                    <div v-if="functionConfig.description">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Description
                                        </dt>
                                        <dd class="mt-1">
                                            {{ functionConfig.description }}
                                        </dd>
                                    </div>
                                    <div v-if="functionConfig.parameters">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Parameters Schema
                                        </dt>
                                        <dd class="mt-2">
                                            <pre
                                                class="rounded-md bg-muted p-4 font-mono text-sm whitespace-pre-wrap"
                                                >{{
                                                    JSON.stringify(
                                                        functionConfig.parameters,
                                                        null,
                                                        2,
                                                    )
                                                }}</pre
                                            >
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    </div>

                    <div v-if="tool.type === 'mcp' && mcpConfig">
                        <HeadingSmall
                            title="MCP Configuration"
                            description="Model Context Protocol server settings"
                        />
                        <Card class="mt-4">
                            <CardContent class="pt-6">
                                <dl class="grid gap-4 sm:grid-cols-2">
                                    <div v-if="mcpConfig.endpoint">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Endpoint
                                        </dt>
                                        <dd class="mt-1 font-mono text-sm">
                                            {{ mcpConfig.endpoint }}
                                        </dd>
                                    </div>
                                    <div v-if="mcpConfig.auth_type">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Auth Type
                                        </dt>
                                        <dd class="mt-1 capitalize">
                                            {{ mcpConfig.auth_type }}
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    </div>

                    <div v-if="tool.type === 'rest_api' && restApiConfig">
                        <HeadingSmall
                            title="REST API Configuration"
                            description="HTTP endpoint integration settings"
                        />
                        <Card class="mt-4">
                            <CardContent class="pt-6">
                                <dl class="grid gap-4 sm:grid-cols-2">
                                    <div v-if="restApiConfig.base_url">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Base URL
                                        </dt>
                                        <dd class="mt-1 font-mono text-sm">
                                            {{ restApiConfig.base_url }}
                                        </dd>
                                    </div>
                                    <div v-if="restApiConfig.method">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Method
                                        </dt>
                                        <dd class="mt-1">
                                            <Badge variant="outline">{{
                                                restApiConfig.method
                                            }}</Badge>
                                        </dd>
                                    </div>
                                    <div v-if="restApiConfig.path">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Path
                                        </dt>
                                        <dd class="mt-1 font-mono text-sm">
                                            {{ restApiConfig.path }}
                                        </dd>
                                    </div>
                                    <div v-if="restApiConfig.auth_type">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Auth Type
                                        </dt>
                                        <dd class="mt-1 capitalize">
                                            {{ restApiConfig.auth_type }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="restApiConfig.auth_config_is_set"
                                    >
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Credentials
                                        </dt>
                                        <dd class="mt-1 text-green-600">
                                            Configured
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    </div>

                    <div v-if="tool.type === 'graphql' && graphqlConfig">
                        <HeadingSmall
                            title="GraphQL Configuration"
                            description="GraphQL API integration settings"
                        />
                        <Card class="mt-4">
                            <CardContent class="pt-6">
                                <dl class="space-y-4">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div v-if="graphqlConfig.endpoint">
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Endpoint
                                            </dt>
                                            <dd class="mt-1 font-mono text-sm">
                                                {{ graphqlConfig.endpoint }}
                                            </dd>
                                        </div>
                                        <div
                                            v-if="graphqlConfig.operation_type"
                                        >
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Operation Type
                                            </dt>
                                            <dd class="mt-1 capitalize">
                                                {{
                                                    graphqlConfig.operation_type
                                                }}
                                            </dd>
                                        </div>
                                        <div v-if="graphqlConfig.auth_type">
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Auth Type
                                            </dt>
                                            <dd class="mt-1 capitalize">
                                                {{ graphqlConfig.auth_type }}
                                            </dd>
                                        </div>
                                        <div
                                            v-if="
                                                graphqlConfig.auth_config_is_set
                                            "
                                        >
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Credentials
                                            </dt>
                                            <dd class="mt-1 text-green-600">
                                                Configured
                                            </dd>
                                        </div>
                                    </div>
                                    <div v-if="graphqlConfig.operation">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Operation
                                        </dt>
                                        <dd class="mt-2">
                                            <pre
                                                class="rounded-md bg-muted p-4 font-mono text-sm whitespace-pre-wrap"
                                                >{{
                                                    graphqlConfig.operation
                                                }}</pre
                                            >
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    </div>

                    <div v-if="tool.type === 'database' && databaseConfig">
                        <HeadingSmall
                            title="Database Configuration"
                            description="Database connection and query settings"
                        />
                        <Card class="mt-4">
                            <CardContent class="pt-6">
                                <dl class="space-y-4">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div v-if="databaseConfig.driver">
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Driver
                                            </dt>
                                            <dd class="mt-1 uppercase">
                                                {{ databaseConfig.driver }}
                                            </dd>
                                        </div>
                                        <div v-if="databaseConfig.host">
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Host
                                            </dt>
                                            <dd class="mt-1 font-mono text-sm">
                                                {{ databaseConfig.host }}:{{
                                                    databaseConfig.port
                                                }}
                                            </dd>
                                        </div>
                                        <div v-if="databaseConfig.database">
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Database
                                            </dt>
                                            <dd class="mt-1 font-mono text-sm">
                                                {{ databaseConfig.database }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Mode
                                            </dt>
                                            <dd class="mt-1">
                                                <Badge
                                                    :variant="
                                                        databaseConfig.read_only
                                                            ? 'secondary'
                                                            : 'destructive'
                                                    "
                                                >
                                                    {{
                                                        databaseConfig.read_only
                                                            ? 'Read Only'
                                                            : 'Read/Write'
                                                    }}
                                                </Badge>
                                            </dd>
                                        </div>
                                        <div
                                            v-if="
                                                databaseConfig.username_is_set ||
                                                databaseConfig.password_is_set
                                            "
                                        >
                                            <dt
                                                class="text-sm font-medium text-muted-foreground"
                                            >
                                                Credentials
                                            </dt>
                                            <dd class="mt-1 text-green-600">
                                                Configured
                                            </dd>
                                        </div>
                                    </div>
                                    <div v-if="databaseConfig.query_template">
                                        <dt
                                            class="text-sm font-medium text-muted-foreground"
                                        >
                                            Query Template
                                        </dt>
                                        <dd class="mt-2">
                                            <pre
                                                class="rounded-md bg-muted p-4 font-mono text-sm whitespace-pre-wrap"
                                                >{{
                                                    databaseConfig.query_template
                                                }}</pre
                                            >
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    </div>

                    <div
                        v-if="
                            tool.type === 'group' &&
                            tool.group_items &&
                            tool.group_items.length > 0
                        "
                    >
                        <HeadingSmall
                            title="Group Members"
                            description="Tools included in this group"
                        />
                        <div class="mt-4 space-y-3">
                            <Card
                                v-for="item in tool.group_items"
                                :key="item.id"
                            >
                                <CardContent
                                    class="flex items-center gap-3 py-4"
                                >
                                    <component
                                        :is="
                                            toolIcon(
                                                item.tool?.type ?? 'function',
                                            )
                                        "
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <span>{{ item.tool?.name }}</span>
                                    <Badge
                                        variant="outline"
                                        class="ml-auto capitalize"
                                    >
                                        {{ item.tool?.type }}
                                    </Badge>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
        </div>
    </AppLayoutV2>
</template>
