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
import { Code, Layers, Pencil, Server, Trash2, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    tool: Tool;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tools', href: ToolController.index().url },
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
                                Edit
                            </Link>
                        </Button>
                        <Dialog>
                            <DialogTrigger as-child>
                                <Button variant="destructive">
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    Delete
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Delete Tool</DialogTitle>
                                    <DialogDescription>
                                        Are you sure you want to delete "{{ tool.name }}"? This action cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="outline">Cancel</Button>
                                    </DialogClose>
                                    <Button variant="destructive" @click="deleteTool">
                                        Delete
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <div class="space-y-8">
                    <Card>
                        <CardHeader>
                            <CardTitle>Configuration</CardTitle>
                            <CardDescription>
                                Tool type and settings
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">Type</dt>
                                    <dd class="mt-1 capitalize">{{ tool.type }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">Validated</dt>
                                    <dd class="mt-1">{{ tool.is_validated ? 'Yes' : 'No' }}</dd>
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
