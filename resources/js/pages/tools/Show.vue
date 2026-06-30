<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import ToolDetailCard from '@/components/tools/ToolDetailCard.vue';
import { Input } from '@/components/ui/input';
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
    ListChecks,
    Pencil,
    Plug,
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

interface LinkedIntegration {
    id: string;
    name: string;
    is_mcp: boolean;
}

interface Props {
    tool: Tool;
    mcpAuthorization?: McpAuthorization | null;
    linkedIntegration?: LinkedIntegration | null;
}

const props = withDefaults(defineProps<Props>(), {
    mcpAuthorization: null,
    linkedIntegration: null,
});

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

// Per-type tint, mirrored from ToolTypeSelector so the type reads consistently
// from the picker through create and into this detail view.
const typeTint = (type: ToolType): string => {
    switch (type) {
        case 'function':
            return 'var(--sp-accent-blue)';
        case 'mcp':
            return 'var(--sp-success)';
        case 'group':
            return 'var(--sp-spectrum-magenta)';
        case 'rest_api':
            return 'var(--sp-warning)';
        case 'graphql':
            return 'var(--sp-spectrum-indigo)';
        case 'database':
            return 'var(--sp-accent-cyan)';
        default:
            return 'var(--sp-accent-blue)';
    }
};

const statusTint: Record<string, string> = {
    active: 'var(--sp-success)',
    inactive: 'var(--sp-text-secondary)',
    draft: 'var(--sp-accent-blue)',
};
const tintForStatus = (status: string) =>
    statusTint[status] ?? 'var(--sp-text-secondary)';

const deleteTool = () => {
    router.delete(ToolController.destroy({ tool: props.tool.id }).url);
};

interface JsonSchema {
    properties?: Record<string, { type?: string; description?: string }>;
    required?: string[];
}

const functionConfig = computed(() => {
    if (props.tool.type !== 'function' || !props.tool.config) return null;
    return props.tool.config as {
        name?: string;
        description?: string;
        parameters?: JsonSchema;
    };
});

// Flatten the JSON Schema into table rows — the readable mirror of the builder.
const functionParams = computed(() => {
    const schema = functionConfig.value?.parameters;
    if (!schema?.properties) return [];
    const required = Array.isArray(schema.required) ? schema.required : [];
    return Object.entries(schema.properties).map(([name, def]) => ({
        name,
        type: def?.type ?? 'string',
        description: def?.description ?? '',
        required: required.includes(name),
    }));
});

const mcpConfig = computed(() => {
    if (props.tool.type !== 'mcp' || !props.tool.config) return null;
    return props.tool.config as { endpoint?: string; auth_type?: string };
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
            <!-- Header. -->
            <div class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xs"
                        :style="{
                            backgroundColor: `color-mix(in oklab, ${typeTint(tool.type)} 15%, transparent)`,
                            color: typeTint(tool.type),
                        }"
                    >
                        <component :is="toolIcon(tool.type)" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2.5">
                            <h1 class="truncate text-[22px] font-semibold leading-tight text-ink">
                                {{ tool.name }}
                            </h1>
                            <span
                                class="inline-flex shrink-0 items-center rounded-pill border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider"
                                :style="{
                                    color: tintForStatus(tool.status),
                                    borderColor: `color-mix(in oklab, ${tintForStatus(tool.status)} 45%, transparent)`,
                                }"
                            >
                                {{ tool.status }}
                            </span>
                        </div>
                        <p v-if="tool.description" class="mt-1 text-xs text-ink-muted">
                            {{ tool.description }}
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Link :href="ToolController.edit({ tool: tool.id }).url">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                        >
                            <Pencil class="size-3.5" />
                            {{ t('common.edit') }}
                        </button>
                    </Link>
                    <Dialog>
                        <DialogTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-sp-danger/50 bg-sp-danger/10 px-3.5 py-1.5 text-xs font-medium text-sp-danger transition-colors hover:border-sp-danger hover:bg-sp-danger/20"
                            >
                                <Trash2 class="size-3.5" />
                                {{ t('common.delete') }}
                            </button>
                        </DialogTrigger>
                        <DialogContent class="sp-admin-dialog">
                            <DialogHeader>
                                <DialogTitle>{{ t('tools.show.delete_tool') }}</DialogTitle>
                                <DialogDescription>
                                    {{ t('common.confirm_delete') }} "{{ tool.name }}"?
                                    {{ t('common.action_irreversible') }}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3.5 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                                    >
                                        {{ t('common.cancel') }}
                                    </button>
                                </DialogClose>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-pill bg-sp-danger px-3.5 py-1.5 text-xs font-medium text-white transition-colors hover:brightness-110"
                                    @click="deleteTool"
                                >
                                    <Trash2 class="size-3.5" />
                                    {{ t('common.delete') }}
                                </button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Per-user MCP authorization — specific to each member. -->
                <div
                    v-if="mcpAuthorization && !mcpAuthorization.connected"
                    class="flex items-start gap-3 rounded-sp-sm border border-sp-warning/30 bg-sp-warning/10 p-4"
                >
                    <KeyRound class="mt-0.5 size-5 shrink-0 text-sp-warning" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink">
                            {{ t('tools.show.authorize_title') }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ t('tools.show.authorize_hint') }}
                        </p>
                    </div>
                    <a
                        :href="mcpAuthorization.authorize_url"
                        class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-pill bg-sp-warning px-3.5 py-1.5 text-xs font-medium text-navy-deep transition-colors hover:brightness-110"
                    >
                        <KeyRound class="size-3.5" />
                        {{ t('tools.show.authorize_cta') }}
                    </a>
                </div>

                <div
                    v-else-if="mcpAuthorization && mcpAuthorization.connected"
                    class="flex items-start gap-3 rounded-sp-sm border border-sp-success/30 bg-sp-success/10 p-4"
                >
                    <CheckCircle2 class="mt-0.5 size-5 shrink-0 text-sp-success" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink">
                            {{ t('tools.show.connected_title') }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ t('tools.show.connected_hint') }}
                        </p>
                    </div>
                    <a
                        :href="mcpAuthorization.authorize_url"
                        class="inline-flex shrink-0 items-center gap-1.5 self-center rounded-pill border border-medium bg-surface px-3 py-1.5 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover"
                    >
                        {{ t('tools.show.reauthorize_cta') }}
                    </a>
                </div>

                <!-- Overview. -->
                <ToolDetailCard
                    :title="t('tools.show.configuration')"
                    :description="t('tools.show.config_description')"
                    :icon="toolIcon(tool.type)"
                    :tint="typeTint(tool.type)"
                >
                    <dl class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('common.type') }}
                            </dt>
                            <dd class="mt-1 text-sm capitalize text-ink">{{ tool.type }}</dd>
                        </div>
                        <div v-if="linkedIntegration">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.integration') }}
                            </dt>
                            <dd class="mt-1">
                                <Link
                                    :href="`/system/integrations/${linkedIntegration.id}`"
                                    class="inline-flex items-center gap-1.5 text-sm text-accent-blue transition-colors hover:underline"
                                >
                                    <Plug class="size-3.5" />
                                    {{ linkedIntegration.name }}
                                </Link>
                            </dd>
                        </div>
                        <div v-if="mcpAuthorization">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.connection') }}
                            </dt>
                            <dd
                                class="mt-1 text-sm"
                                :class="mcpAuthorization.connected ? 'text-sp-success' : 'text-sp-warning'"
                            >
                                {{
                                    mcpAuthorization.connected
                                        ? t('tools.show.connected')
                                        : t('tools.show.not_connected')
                                }}
                            </dd>
                        </div>
                        <div v-else>
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.validated') }}
                            </dt>
                            <dd class="mt-1 text-sm text-ink">
                                {{ tool.is_validated ? t('common.yes') : t('common.no') }}
                            </dd>
                        </div>
                    </dl>
                </ToolDetailCard>

                <!-- MCP server tool catalog. -->
                <ToolDetailCard
                    v-if="tool.type === 'mcp'"
                    :title="t('tools.show.mcp_tools_title')"
                    :description="t('tools.show.mcp_tools_description')"
                    :icon="Wrench"
                    :tint="typeTint('mcp')"
                >
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="mcpServerTools.length > 0"
                                class="inline-flex items-center rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] font-medium text-ink-muted"
                            >
                                {{ t('tools.show.mcp_tools_count', { count: mcpServerTools.length }) }}
                            </span>
                            <button
                                type="button"
                                :disabled="refreshing"
                                class="inline-flex items-center gap-1.5 rounded-pill border border-medium bg-surface px-3 py-1 text-xs text-ink transition-colors hover:border-strong hover:bg-surface-hover disabled:opacity-50"
                                @click="reloadMcpTools"
                            >
                                <RefreshCw :class="['size-3.5', refreshing ? 'animate-spin' : '']" />
                                {{ t('tools.show.reload_tools') }}
                            </button>
                        </div>
                    </template>

                    <p
                        v-if="mcpSyncedAt"
                        class="mb-3 text-[11px] text-ink-subtle"
                    >
                        {{ t('tools.show.mcp_synced_at') }}
                        {{ new Date(mcpSyncedAt).toLocaleString() }}
                    </p>
                    <p
                        v-if="refreshError"
                        class="mb-3 rounded-xs border border-sp-danger/30 bg-sp-danger/10 p-2 text-xs text-sp-danger"
                    >
                        {{ refreshError }}
                    </p>

                    <template v-if="mcpServerTools.length > 0">
                        <div class="relative mb-3">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-ink-subtle" />
                            <Input
                                v-model="mcpSearch"
                                :placeholder="t('tools.show.mcp_search_placeholder')"
                                class="h-9 pl-9"
                            />
                        </div>

                        <div v-if="filteredMcpTools.length > 0" class="grid gap-2 sm:grid-cols-2">
                            <div
                                v-for="serverTool in filteredMcpTools"
                                :key="serverTool.name"
                                :title="serverTool.description"
                                class="rounded-xs border border-soft bg-white/[0.02] p-3 transition-colors hover:border-strong hover:bg-white/[0.04]"
                            >
                                <div class="flex items-center gap-2">
                                    <Wrench class="size-3.5 shrink-0 text-ink-subtle" />
                                    <p class="truncate font-mono text-sm font-medium text-ink">
                                        {{ serverTool.name }}
                                    </p>
                                </div>
                                <p
                                    v-if="serverTool.description"
                                    class="mt-1 line-clamp-3 text-xs text-ink-muted"
                                >
                                    {{ serverTool.description }}
                                </p>
                                <div
                                    v-if="requiredParams(serverTool).length > 0"
                                    class="mt-2 flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="param in requiredParams(serverTool)"
                                        :key="param"
                                        class="inline-flex items-center rounded-pill border border-soft bg-surface px-2 py-0.5 font-mono text-[10px] text-ink-muted"
                                    >
                                        {{ param }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <p v-else class="py-6 text-center text-sm text-ink-muted">
                            {{ t('tools.show.mcp_no_results') }}
                        </p>
                    </template>

                    <div
                        v-else
                        class="rounded-xs border border-dashed border-soft bg-white/[0.02] p-6 text-center"
                    >
                        <Server class="mx-auto size-6 text-ink-subtle" />
                        <p class="mt-2 text-sm text-ink-muted">
                            {{ t('tools.show.mcp_no_tools') }}
                        </p>
                    </div>
                </ToolDetailCard>

                <!-- Function: definition + parameter table. -->
                <ToolDetailCard
                    v-if="tool.type === 'function' && functionConfig"
                    :title="t('tools.show.sec.function')"
                    :description="t('tools.show.sec.function_desc')"
                    :icon="Code"
                    :tint="typeTint('function')"
                >
                    <div class="space-y-4">
                        <div v-if="functionConfig.name">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.config.function.name') }}
                            </dt>
                            <dd class="mt-1 font-mono text-sm text-ink">{{ functionConfig.name }}</dd>
                        </div>

                        <div>
                            <dt class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.config.function.parameters') }}
                            </dt>
                            <div
                                v-if="functionParams.length === 0"
                                class="rounded-xs border border-dashed border-soft bg-white/[0.02] px-4 py-4 text-center text-xs text-ink-muted"
                            >
                                {{ t('tools.config.function.no_params') }}
                            </div>
                            <div v-else class="overflow-hidden rounded-xs border border-soft">
                                <div
                                    class="grid grid-cols-[1fr_90px_1.6fr] gap-3 border-b border-soft bg-white/[0.03] px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-ink-faint"
                                >
                                    <span>{{ t('tools.config.function.param_name') }}</span>
                                    <span>{{ t('tools.config.function.param_type') }}</span>
                                    <span>{{ t('tools.config.function.param_description') }}</span>
                                </div>
                                <div
                                    v-for="param in functionParams"
                                    :key="param.name"
                                    class="grid grid-cols-[1fr_90px_1.6fr] items-center gap-3 border-b border-soft px-3 py-2 last:border-b-0"
                                >
                                    <span class="flex items-center gap-1.5 truncate font-mono text-xs text-ink">
                                        {{ param.name }}
                                        <span
                                            v-if="param.required"
                                            :title="t('tools.config.function.param_required')"
                                            class="text-sp-danger"
                                            >*</span
                                        >
                                    </span>
                                    <span class="text-xs text-ink-muted">{{ param.type }}</span>
                                    <span class="truncate text-xs text-ink-muted">{{ param.description || '—' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </ToolDetailCard>

                <!-- MCP server settings. -->
                <ToolDetailCard
                    v-if="tool.type === 'mcp' && mcpConfig"
                    :title="t('tools.show.sec.mcp')"
                    :description="t('tools.show.sec.mcp_desc')"
                    :icon="Server"
                    :tint="typeTint('mcp')"
                >
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div v-if="mcpConfig.endpoint">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.endpoint') }}
                            </dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink">{{ mcpConfig.endpoint }}</dd>
                        </div>
                        <div v-if="mcpConfig.auth_type">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.auth') }}
                            </dt>
                            <dd class="mt-1 text-sm capitalize text-ink">{{ mcpConfig.auth_type }}</dd>
                        </div>
                    </dl>
                </ToolDetailCard>

                <!-- REST request. -->
                <ToolDetailCard
                    v-if="tool.type === 'rest_api' && restApiConfig"
                    :title="t('tools.show.sec.rest')"
                    :description="t('tools.show.sec.rest_desc')"
                    :icon="Globe"
                    :tint="typeTint('rest_api')"
                >
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div v-if="restApiConfig.method">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.method') }}
                            </dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center rounded-pill border border-soft bg-surface px-2 py-0.5 font-mono text-[11px] font-semibold text-ink">
                                    {{ restApiConfig.method }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="restApiConfig.path">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.path') }}
                            </dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink">{{ restApiConfig.path }}</dd>
                        </div>
                        <div v-if="restApiConfig.base_url">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.base_url') }}
                            </dt>
                            <dd class="mt-1 break-all font-mono text-xs text-ink">{{ restApiConfig.base_url }}</dd>
                        </div>
                        <div v-if="restApiConfig.auth_config_is_set">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.credentials') }}
                            </dt>
                            <dd class="mt-1 text-sm text-sp-success">{{ t('tools.show.f.configured') }}</dd>
                        </div>
                    </dl>
                </ToolDetailCard>

                <!-- GraphQL operation. -->
                <ToolDetailCard
                    v-if="tool.type === 'graphql' && graphqlConfig"
                    :title="t('tools.show.sec.graphql')"
                    :description="t('tools.show.sec.graphql_desc')"
                    :icon="Braces"
                    :tint="typeTint('graphql')"
                >
                    <div class="space-y-4">
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div v-if="graphqlConfig.endpoint">
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                    {{ t('tools.show.f.endpoint') }}
                                </dt>
                                <dd class="mt-1 break-all font-mono text-xs text-ink">{{ graphqlConfig.endpoint }}</dd>
                            </div>
                            <div v-if="graphqlConfig.operation_type">
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                    {{ t('tools.show.f.operation_type') }}
                                </dt>
                                <dd class="mt-1 text-sm capitalize text-ink">{{ graphqlConfig.operation_type }}</dd>
                            </div>
                        </dl>
                        <div v-if="graphqlConfig.operation">
                            <dt class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.operation') }}
                            </dt>
                            <pre class="overflow-x-auto rounded-xs border border-soft bg-navy-deep p-3 font-mono text-xs whitespace-pre-wrap text-ink">{{ graphqlConfig.operation }}</pre>
                        </div>
                    </div>
                </ToolDetailCard>

                <!-- Database query. -->
                <ToolDetailCard
                    v-if="tool.type === 'database' && databaseConfig"
                    :title="t('tools.show.sec.database')"
                    :description="t('tools.show.sec.database_desc')"
                    :icon="Database"
                    :tint="typeTint('database')"
                >
                    <div class="space-y-4">
                        <dl class="grid gap-4 sm:grid-cols-3">
                            <div v-if="databaseConfig.driver">
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                    {{ t('tools.show.f.driver') }}
                                </dt>
                                <dd class="mt-1 text-sm uppercase text-ink">{{ databaseConfig.driver }}</dd>
                            </div>
                            <div v-if="databaseConfig.host">
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                    {{ t('tools.show.f.host') }}
                                </dt>
                                <dd class="mt-1 break-all font-mono text-xs text-ink">
                                    {{ databaseConfig.host }}:{{ databaseConfig.port }}
                                </dd>
                            </div>
                            <div v-if="databaseConfig.database">
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                    {{ t('tools.show.f.database') }}
                                </dt>
                                <dd class="mt-1 break-all font-mono text-xs text-ink">{{ databaseConfig.database }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                    {{ t('tools.show.f.mode') }}
                                </dt>
                                <dd class="mt-1">
                                    <span
                                        class="inline-flex items-center rounded-pill border px-2 py-0.5 text-[10px] font-medium"
                                        :class="
                                            databaseConfig.read_only
                                                ? 'border-sp-success/40 bg-sp-success/10 text-sp-success'
                                                : 'border-sp-danger/40 bg-sp-danger/10 text-sp-danger'
                                        "
                                    >
                                        {{ databaseConfig.read_only ? t('tools.show.f.read_only') : t('tools.show.f.read_write') }}
                                    </span>
                                </dd>
                            </div>
                            <div v-if="databaseConfig.username_is_set || databaseConfig.password_is_set">
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                    {{ t('tools.show.f.credentials') }}
                                </dt>
                                <dd class="mt-1 text-sm text-sp-success">{{ t('tools.show.f.configured') }}</dd>
                            </div>
                        </dl>
                        <div v-if="databaseConfig.query_template">
                            <dt class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-ink-faint">
                                {{ t('tools.show.f.query') }}
                            </dt>
                            <pre class="overflow-x-auto rounded-xs border border-soft bg-navy-deep p-3 font-mono text-xs whitespace-pre-wrap text-ink">{{ databaseConfig.query_template }}</pre>
                        </div>
                    </div>
                </ToolDetailCard>

                <!-- Group members. -->
                <ToolDetailCard
                    v-if="tool.type === 'group' && tool.group_items && tool.group_items.length > 0"
                    :title="t('tools.show.sec.group')"
                    :description="t('tools.show.sec.group_desc')"
                    :icon="ListChecks"
                    :tint="typeTint('group')"
                >
                    <ul class="divide-y divide-soft overflow-hidden rounded-xs border border-soft">
                        <li
                            v-for="item in tool.group_items"
                            :key="item.id"
                            class="flex items-center gap-3 bg-white/[0.02] px-4 py-3"
                        >
                            <div
                                class="flex size-7 shrink-0 items-center justify-center rounded-xs"
                                :style="{
                                    backgroundColor: `color-mix(in oklab, ${typeTint(item.tool?.type ?? 'function')} 15%, transparent)`,
                                    color: typeTint(item.tool?.type ?? 'function'),
                                }"
                            >
                                <component :is="toolIcon(item.tool?.type ?? 'function')" class="size-3.5" />
                            </div>
                            <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ item.tool?.name }}</span>
                            <span class="shrink-0 rounded-pill border border-soft bg-surface px-2 py-0.5 text-[10px] capitalize text-ink-muted">
                                {{ item.tool?.type }}
                            </span>
                        </li>
                    </ul>
                </ToolDetailCard>
            </div>
        </div>
    </AppLayoutV2>
</template>
