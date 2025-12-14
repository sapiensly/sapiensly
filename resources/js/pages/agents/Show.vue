<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
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
import type { AgentTeam } from '@/types/agents';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bot, Brain, Pencil, Trash2, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    team: AgentTeam;
}

const props = defineProps<Props>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Agent Teams', href: AgentTeamController.index().url },
    { title: props.team.name, href: '#' },
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

const agentIcon = (type: string) => {
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

const deleteTeam = () => {
    router.delete(AgentTeamController.destroy({ agent_team: props.team.id }).url);
};
</script>

<template>
    <Head :title="team.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mx-auto max-w-4xl">
                <div class="mb-8 flex items-start justify-between">
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <Heading :title="team.name" />
                            <Badge :variant="statusVariant(team.status)">
                                {{ team.status }}
                            </Badge>
                        </div>
                        <p
                            v-if="team.description"
                            class="text-muted-foreground"
                        >
                            {{ team.description }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    AgentTeamController.edit({
                                        agent_team: team.id,
                                    })
                                "
                            >
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
                                    <DialogTitle>Delete Agent Team</DialogTitle>
                                    <DialogDescription>
                                        Are you sure you want to delete "{{
                                            team.name
                                        }}"? This action cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="outline">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        variant="destructive"
                                        @click="deleteTeam"
                                    >
                                        Delete
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <div class="space-y-6">
                    <HeadingSmall
                        title="Agents"
                        description="The agents in this team"
                    />

                    <div class="grid gap-4">
                        <Card v-for="agent in team.agents" :key="agent.id">
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <component
                                        :is="agentIcon(agent.type)"
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <div class="flex-1">
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <CardTitle class="text-base">
                                                {{ agent.name }}
                                            </CardTitle>
                                            <Badge
                                                :variant="
                                                    statusVariant(agent.status)
                                                "
                                                class="text-xs"
                                            >
                                                {{ agent.status }}
                                            </Badge>
                                        </div>
                                        <CardDescription
                                            v-if="agent.description"
                                        >
                                            {{ agent.description }}
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <dl class="grid gap-2 text-sm">
                                    <div class="flex gap-2">
                                        <dt class="text-muted-foreground">
                                            Type:
                                        </dt>
                                        <dd class="capitalize">
                                            {{ agent.type }}
                                        </dd>
                                    </div>
                                    <div class="flex gap-2">
                                        <dt class="text-muted-foreground">
                                            Model:
                                        </dt>
                                        <dd>{{ agent.model }}</dd>
                                    </div>
                                    <div
                                        v-if="agent.prompt_template"
                                        class="mt-2"
                                    >
                                        <dt
                                            class="mb-1 text-muted-foreground"
                                        >
                                            Prompt Template:
                                        </dt>
                                        <dd
                                            class="whitespace-pre-wrap rounded-md bg-muted p-3 font-mono text-xs"
                                        >
                                            {{ agent.prompt_template }}
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
