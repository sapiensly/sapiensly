<script setup lang="ts">
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Tool, ToolType } from '@/types/tools';
import { Head, Link, router } from '@inertiajs/vue3';
import { Braces, Code, Database, Globe, Layers, Pencil, Server, Trash2, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    tool: Tool;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('tools.index.heading'), href: ToolController.index().url },
    { title: props.tool.name, href: '#' },
]);

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

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <component
                                :is="toolIcon(tool.type)"
                                class="h-6 w-6 text-muted-foreground"
                            />
                            <Heading :title="tool.name" />
                            <Badge :variant="statusVariant(tool.status)">
                                {{ tool.status }}
                            </Badge>
                        </div>
                        <p v-if="tool.description" class="text-muted-foreground">
                            {{ tool.description }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="ToolController.edit({ tool: tool.id }).url">
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
                                    <DialogTitle>{{ t('tools.show.delete_tool') }}</DialogTitle>
                                    <DialogDescription>
                                        {{ t('common.confirm_delete') }} "{{ tool.name }}"? {{ t('common.action_irreversible') }}
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="outline">{{ t('common.cancel') }}</Button>
                                    </DialogClose>
                                    <Button variant="destructive" @click="deleteTool">
                                        {{ t('common.delete') }}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <div class="space-y-8">
                    <Card>
                        <CardHeader>
                            <CardTitle>{{ t('tools.show.configuration') }}</CardTitle>
                            <CardDescription>
                                {{ t('tools.show.config_description') }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">{{ t('common.type') }}</dt>
                                    <dd class="mt-1 capitalize">{{ tool.type }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">{{ t('tools.show.validated') }}</dt>
                                    <dd class="mt-1">{{ tool.is_validated ? t('common.yes') : t('common.no') }}</dd>
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
                                        <dt class="text-sm font-medium text-muted-foreground">Function Name</dt>
                                        <dd class="mt-1 font-mono">{{ functionConfig.name }}</dd>
                                    </div>
                                    <div v-if="functionConfig.description">
                                        <dt class="text-sm font-medium text-muted-foreground">Description</dt>
                                        <dd class="mt-1">{{ functionConfig.description }}</dd>
                                    </div>
                                    <div v-if="functionConfig.parameters">
                                        <dt class="text-sm font-medium text-muted-foreground">Parameters Schema</dt>
                                        <dd class="mt-2">
                                            <pre class="whitespace-pre-wrap rounded-md bg-muted p-4 font-mono text-sm">{{ JSON.stringify(functionConfig.parameters, null, 2) }}</pre>
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
                                        <dt class="text-sm font-medium text-muted-foreground">Endpoint</dt>
                                        <dd class="mt-1 font-mono text-sm">{{ mcpConfig.endpoint }}</dd>
                                    </div>
                                    <div v-if="mcpConfig.auth_type">
                                        <dt class="text-sm font-medium text-muted-foreground">Auth Type</dt>
                                        <dd class="mt-1 capitalize">{{ mcpConfig.auth_type }}</dd>
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
                                        <dt class="text-sm font-medium text-muted-foreground">Base URL</dt>
                                        <dd class="mt-1 font-mono text-sm">{{ restApiConfig.base_url }}</dd>
                                    </div>
                                    <div v-if="restApiConfig.method">
                                        <dt class="text-sm font-medium text-muted-foreground">Method</dt>
                                        <dd class="mt-1">
                                            <Badge variant="outline">{{ restApiConfig.method }}</Badge>
                                        </dd>
                                    </div>
                                    <div v-if="restApiConfig.path">
                                        <dt class="text-sm font-medium text-muted-foreground">Path</dt>
                                        <dd class="mt-1 font-mono text-sm">{{ restApiConfig.path }}</dd>
                                    </div>
                                    <div v-if="restApiConfig.auth_type">
                                        <dt class="text-sm font-medium text-muted-foreground">Auth Type</dt>
                                        <dd class="mt-1 capitalize">{{ restApiConfig.auth_type }}</dd>
                                    </div>
                                    <div v-if="restApiConfig.auth_config_is_set">
                                        <dt class="text-sm font-medium text-muted-foreground">Credentials</dt>
                                        <dd class="mt-1 text-green-600">Configured</dd>
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
                                            <dt class="text-sm font-medium text-muted-foreground">Endpoint</dt>
                                            <dd class="mt-1 font-mono text-sm">{{ graphqlConfig.endpoint }}</dd>
                                        </div>
                                        <div v-if="graphqlConfig.operation_type">
                                            <dt class="text-sm font-medium text-muted-foreground">Operation Type</dt>
                                            <dd class="mt-1 capitalize">{{ graphqlConfig.operation_type }}</dd>
                                        </div>
                                        <div v-if="graphqlConfig.auth_type">
                                            <dt class="text-sm font-medium text-muted-foreground">Auth Type</dt>
                                            <dd class="mt-1 capitalize">{{ graphqlConfig.auth_type }}</dd>
                                        </div>
                                        <div v-if="graphqlConfig.auth_config_is_set">
                                            <dt class="text-sm font-medium text-muted-foreground">Credentials</dt>
                                            <dd class="mt-1 text-green-600">Configured</dd>
                                        </div>
                                    </div>
                                    <div v-if="graphqlConfig.operation">
                                        <dt class="text-sm font-medium text-muted-foreground">Operation</dt>
                                        <dd class="mt-2">
                                            <pre class="whitespace-pre-wrap rounded-md bg-muted p-4 font-mono text-sm">{{ graphqlConfig.operation }}</pre>
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
                                            <dt class="text-sm font-medium text-muted-foreground">Driver</dt>
                                            <dd class="mt-1 uppercase">{{ databaseConfig.driver }}</dd>
                                        </div>
                                        <div v-if="databaseConfig.host">
                                            <dt class="text-sm font-medium text-muted-foreground">Host</dt>
                                            <dd class="mt-1 font-mono text-sm">{{ databaseConfig.host }}:{{ databaseConfig.port }}</dd>
                                        </div>
                                        <div v-if="databaseConfig.database">
                                            <dt class="text-sm font-medium text-muted-foreground">Database</dt>
                                            <dd class="mt-1 font-mono text-sm">{{ databaseConfig.database }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-sm font-medium text-muted-foreground">Mode</dt>
                                            <dd class="mt-1">
                                                <Badge :variant="databaseConfig.read_only ? 'secondary' : 'destructive'">
                                                    {{ databaseConfig.read_only ? 'Read Only' : 'Read/Write' }}
                                                </Badge>
                                            </dd>
                                        </div>
                                        <div v-if="databaseConfig.username_is_set || databaseConfig.password_is_set">
                                            <dt class="text-sm font-medium text-muted-foreground">Credentials</dt>
                                            <dd class="mt-1 text-green-600">Configured</dd>
                                        </div>
                                    </div>
                                    <div v-if="databaseConfig.query_template">
                                        <dt class="text-sm font-medium text-muted-foreground">Query Template</dt>
                                        <dd class="mt-2">
                                            <pre class="whitespace-pre-wrap rounded-md bg-muted p-4 font-mono text-sm">{{ databaseConfig.query_template }}</pre>
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    </div>

                    <div v-if="tool.type === 'group' && tool.group_items && tool.group_items.length > 0">
                        <HeadingSmall
                            title="Group Members"
                            description="Tools included in this group"
                        />
                        <div class="mt-4 space-y-3">
                            <Card v-for="item in tool.group_items" :key="item.id">
                                <CardContent class="flex items-center gap-3 py-4">
                                    <component
                                        :is="toolIcon(item.tool?.type ?? 'function')"
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <span>{{ item.tool?.name }}</span>
                                    <Badge variant="outline" class="ml-auto capitalize">
                                        {{ item.tool?.type }}
                                    </Badge>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
