<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import * as FlowController from '@/actions/App/Http/Controllers/FlowController';
import Heading from '@/components/Heading.vue';
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
import type { Agent } from '@/types/agents';
import type { Flow } from '@/types/flows';
import { Head, Link, router } from '@inertiajs/vue3';
import { GitBranch, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    agent: Agent;
    flows: Flow[];
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: t('agent_teams.index.title'),
        href: AgentTeamController.index().url,
    },
    {
        title: props.agent.name,
        href: '#',
    },
    {
        title: t('flows.index.title'),
        href: FlowController.index({ agent: props.agent.id }).url,
    },
]);

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

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const deleteFlow = (flow: Flow) => {
    router.delete(
        FlowController.destroy({
            agent: props.agent.id,
            flow: flow.id,
        }).url,
    );
};
</script>

<template>
    <Head :title="t('flows.index.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-center justify-between">
                    <Heading
                        :title="t('flows.index.title')"
                        :description="t('flows.index.description')"
                    />
                    <Button as-child>
                        <Link
                            :href="FlowController.create({ agent: agent.id })"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('flows.index.create_flow') }}
                        </Link>
                    </Button>
                </div>

                <div v-if="flows.length > 0" class="space-y-3">
                    <Card v-for="flow in flows" :key="flow.id">
                        <CardHeader class="pb-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <GitBranch
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <div>
                                        <CardTitle class="text-base">
                                            {{ flow.name }}
                                        </CardTitle>
                                        <CardDescription
                                            v-if="flow.description"
                                        >
                                            {{ flow.description }}
                                        </CardDescription>
                                    </div>
                                </div>
                                <Badge :variant="statusVariant(flow.status)">
                                    {{ t(`flows.status.${flow.status}`) }}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-center justify-between">
                                <div
                                    class="flex items-center gap-4 text-xs text-muted-foreground"
                                >
                                    <span>
                                        {{ t('flows.index.version') }}: v{{
                                            flow.version
                                        }}
                                    </span>
                                    <span>
                                        {{ t('flows.index.updated') }}:
                                        {{ formatDate(flow.updated_at) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        as-child
                                    >
                                        <Link
                                            :href="
                                                FlowController.edit({
                                                    agent: agent.id,
                                                    flow: flow.id,
                                                })
                                            "
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </Link>
                                    </Button>

                                    <Dialog>
                                        <DialogTrigger as-child>
                                            <Button variant="ghost" size="icon">
                                                <Trash2
                                                    class="h-4 w-4 text-destructive"
                                                />
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    {{
                                                        t(
                                                            'flows.index.delete_title',
                                                        )
                                                    }}
                                                </DialogTitle>
                                                <DialogDescription>
                                                    {{
                                                        t(
                                                            'flows.index.delete_description',
                                                            { name: flow.name },
                                                        )
                                                    }}
                                                </DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter>
                                                <DialogClose as-child>
                                                    <Button variant="outline">
                                                        {{ t('common.cancel') }}
                                                    </Button>
                                                </DialogClose>
                                                <Button
                                                    variant="destructive"
                                                    @click="deleteFlow(flow)"
                                                >
                                                    {{ t('common.delete') }}
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center justify-center rounded-lg border border-dashed py-12"
                >
                    <GitBranch class="mb-4 h-10 w-10 text-muted-foreground" />
                    <h3 class="mb-1 text-lg font-medium">
                        {{ t('flows.index.no_flows') }}
                    </h3>
                    <p class="mb-4 text-sm text-muted-foreground">
                        {{ t('flows.index.no_flows_description') }}
                    </p>
                    <Button as-child>
                        <Link
                            :href="FlowController.create({ agent: agent.id })"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            {{ t('flows.index.create_first') }}
                        </Link>
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
