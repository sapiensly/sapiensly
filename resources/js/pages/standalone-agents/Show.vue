<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
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
import type { Agent, AgentType } from '@/types/agents';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bot, Brain, Copy, Database, MessageSquare, Pencil, Trash2, Wrench, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    agent: Agent;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Agents', href: AgentController.index().url },
    { title: props.agent.name, href: '#' },
]);

const agentIcon = (type: AgentType) => {
    switch (type) {
        case 'triage':
            return Bot;
        case 'knowledge':
            return Brain;
        case 'action':
            return Zap;
        default:
            return Bot;
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

const deleteAgent = () => {
    router.delete(AgentController.destroy({ agent: props.agent.id }).url);
};

const duplicateAgent = () => {
    router.post(AgentController.duplicate({ agent: props.agent.id }).url);
};

const configDisplay = computed(() => {
    const config = props.agent.config;
    if (!config) return [];

    const items: { label: string; value: string }[] = [];

    if ('temperature' in config && config.temperature !== undefined) {
        items.push({ label: 'Temperature', value: String(config.temperature) });
    }

    if ('rag_params' in config && config.rag_params) {
        const ragParams = config.rag_params;
        if (ragParams.top_k) {
            items.push({ label: 'Top K', value: String(ragParams.top_k) });
        }
        if (ragParams.similarity_threshold) {
            items.push({ label: 'Similarity Threshold', value: String(ragParams.similarity_threshold) });
        }
    }

    if ('tool_execution' in config && config.tool_execution) {
        const toolExec = config.tool_execution;
        if (toolExec.timeout) {
            items.push({ label: 'Timeout', value: `${toolExec.timeout}ms` });
        }
        if (toolExec.retry_count !== undefined) {
            items.push({ label: 'Retries', value: String(toolExec.retry_count) });
        }
    }

    return items;
});
</script>

<template>
    <Head :title="agent.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <component
                                :is="agentIcon(agent.type)"
                                class="h-6 w-6 text-muted-foreground"
                            />
                            <Heading :title="agent.name" />
                            <Badge :variant="statusVariant(agent.status)">
                                {{ agent.status }}
                            </Badge>
                        </div>
                        <p v-if="agent.description" class="text-muted-foreground">
                            {{ agent.description }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button as-child>
                            <Link :href="AgentController.chat({ agent: agent.id }).url">
                                <MessageSquare class="mr-2 h-4 w-4" />
                                Test Agent
                            </Link>
                        </Button>
                        <Button variant="outline" @click="duplicateAgent">
                            <Copy class="mr-2 h-4 w-4" />
                            Duplicate
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="AgentController.edit({ agent: agent.id }).url">
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
                                    <DialogTitle>Delete Agent</DialogTitle>
                                    <DialogDescription>
                                        Are you sure you want to delete "{{ agent.name }}"? This action cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="outline">Cancel</Button>
                                    </DialogClose>
                                    <Button variant="destructive" @click="deleteAgent">
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
                                Agent type and model settings
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">Type</dt>
                                    <dd class="mt-1 capitalize">{{ agent.type }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-muted-foreground">Model</dt>
                                    <dd class="mt-1">{{ agent.model }}</dd>
                                </div>
                                <div v-for="item in configDisplay" :key="item.label">
                                    <dt class="text-sm font-medium text-muted-foreground">{{ item.label }}</dt>
                                    <dd class="mt-1">{{ item.value }}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <div v-if="agent.prompt_template">
                        <HeadingSmall
                            title="Prompt Template"
                            description="The system prompt for this agent"
                        />
                        <Card class="mt-4">
                            <CardContent class="pt-6">
                                <pre class="whitespace-pre-wrap rounded-md bg-muted p-4 font-mono text-sm">{{ agent.prompt_template }}</pre>
                            </CardContent>
                        </Card>
                    </div>

                    <div v-if="agent.knowledge_bases && agent.knowledge_bases.length > 0">
                        <HeadingSmall
                            title="Knowledge Bases"
                            description="Connected knowledge bases for RAG"
                        />
                        <div class="mt-4 grid gap-3">
                            <Card
                                v-for="kb in agent.knowledge_bases"
                                :key="kb.id"
                            >
                                <CardContent class="flex items-center gap-3 py-4">
                                    <Database class="h-5 w-5 text-muted-foreground" />
                                    <span>{{ kb.name }}</span>
                                </CardContent>
                            </Card>
                        </div>
                    </div>

                    <div v-if="agent.tools && agent.tools.length > 0">
                        <HeadingSmall
                            title="Tools"
                            description="Connected tools for actions"
                        />
                        <div class="mt-4 grid gap-3">
                            <Card v-for="tool in agent.tools" :key="tool.id">
                                <CardContent class="flex items-center gap-3 py-4">
                                    <Wrench class="h-5 w-5 text-muted-foreground" />
                                    <span>{{ tool.name }}</span>
                                    <Badge variant="outline" class="ml-auto">
                                        {{ tool.type }}
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
