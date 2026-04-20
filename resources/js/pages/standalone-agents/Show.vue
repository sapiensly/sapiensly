<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import * as ToolController from '@/actions/App/Http/Controllers/ToolController';
import PageHeader from '@/components/app-v2/PageHeader.vue';
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
import AppLayoutV2 from '@/layouts/AppLayoutV2.vue';
import type { Agent, AgentType } from '@/types/agents';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bot,
    Brain,
    Copy,
    Database,
    MessageSquare,
    Pencil,
    Trash2,
    Wrench,
    Zap,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    agent: Agent;
}

const props = defineProps<Props>();

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
            items.push({
                label: 'Similarity Threshold',
                value: String(ragParams.similarity_threshold),
            });
        }
    }

    if ('tool_execution' in config && config.tool_execution) {
        const toolExec = config.tool_execution;
        if (toolExec.timeout) {
            items.push({ label: 'Timeout', value: `${toolExec.timeout}ms` });
        }
        if (toolExec.retry_count !== undefined) {
            items.push({
                label: 'Retries',
                value: String(toolExec.retry_count),
            });
        }
    }

    return items;
});
</script>

<template>
    <Head :title="agent.name" />

    <AppLayoutV2 :title="t('app_v2.nav.agents')">
        <div class="mx-auto max-w-4xl space-y-6">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <component
                                :is="agentIcon(agent.type)"
                                class="h-6 w-6 text-ink-muted"
                            />
                            <h1 class="text-[22px] font-semibold leading-tight text-ink">{{ agent.name }}</h1>
                            <Badge :variant="statusVariant(agent.status)">
                                {{ agent.status }}
                            </Badge>
                        </div>
                        <p
                            v-if="agent.description"
                            class="text-xs text-ink-muted"
                        >
                            {{ agent.description }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button as-child>
                            <Link
                                :href="
                                    AgentController.chat({ agent: agent.id })
                                        .url
                                "
                            >
                                <MessageSquare class="mr-2 h-4 w-4" />
                                {{ t('agents.show.test_agent') }}
                            </Link>
                        </Button>
                        <Button variant="outline" @click="duplicateAgent">
                            <Copy class="mr-2 h-4 w-4" />
                            {{ t('common.duplicate') }}
                        </Button>
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    AgentController.edit({ agent: agent.id })
                                        .url
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
                                        t('agents.show.delete_agent')
                                    }}</DialogTitle>
                                    <DialogDescription>
                                        {{ t('common.confirm_delete') }} "{{
                                            agent.name
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
                                        @click="deleteAgent"
                                    >
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
                            <CardTitle>{{
                                t('agents.show.configuration')
                            }}</CardTitle>
                            <CardDescription>
                                {{ t('agents.show.config_description') }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{ t('agents.show.type') }}
                                    </dt>
                                    <dd class="mt-1 capitalize">
                                        {{ agent.type }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{ t('agents.show.model') }}
                                    </dt>
                                    <dd class="mt-1">{{ agent.model }}</dd>
                                </div>
                                <div
                                    v-for="item in configDisplay"
                                    :key="item.label"
                                >
                                    <dt
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{ item.label }}
                                    </dt>
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
                                <pre
                                    class="rounded-md bg-muted p-4 font-mono text-sm whitespace-pre-wrap"
                                    >{{ agent.prompt_template }}</pre
                                >
                            </CardContent>
                        </Card>
                    </div>

                    <div
                        v-if="
                            agent.knowledge_bases &&
                            agent.knowledge_bases.length > 0
                        "
                    >
                        <HeadingSmall
                            title="Knowledge Bases"
                            description="Connected knowledge bases for RAG"
                        />
                        <div class="mt-4 grid gap-3">
                            <Card
                                v-for="kb in agent.knowledge_bases"
                                :key="kb.id"
                            >
                                <CardContent
                                    class="flex items-center gap-3 py-4"
                                >
                                    <Database
                                        class="h-5 w-5 text-muted-foreground"
                                    />
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
                            <a
                                v-for="tool in agent.tools"
                                :key="tool.id"
                                :href="ToolController.show.url(tool.id)"
                                target="_blank"
                                class="block"
                            >
                                <Card
                                    class="cursor-pointer transition-colors hover:bg-muted/50"
                                >
                                    <CardContent
                                        class="flex items-center gap-3 py-4"
                                    >
                                        <Wrench
                                            class="h-5 w-5 text-muted-foreground"
                                        />
                                        <span>{{ tool.name }}</span>
                                        <Badge
                                            variant="outline"
                                            class="ml-auto"
                                        >
                                            {{ tool.type }}
                                        </Badge>
                                    </CardContent>
                                </Card>
                            </a>
                        </div>
                    </div>
                </div>
        </div>
    </AppLayoutV2>
</template>
