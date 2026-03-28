<script setup lang="ts">
import * as AgentTeamController from '@/actions/App/Http/Controllers/AgentTeamController';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { AgentTeam } from '@/types/agents';
import { Link } from '@inertiajs/vue3';
import { Users } from 'lucide-vue-next';

defineProps<{
    team: AgentTeam;
}>();

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
</script>

<template>
    <Link :href="AgentTeamController.show({ agent_team: team.id })">
        <Card class="cursor-pointer transition-colors hover:border-primary/50">
            <CardHeader>
                <div class="flex items-center justify-between">
                    <CardTitle class="text-lg">{{ team.name }}</CardTitle>
                    <Badge :variant="statusVariant(team.status)">
                        {{ team.status }}
                    </Badge>
                </div>
                <CardDescription v-if="team.description">
                    {{ team.description }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="flex items-center text-sm text-muted-foreground">
                    <Users class="mr-2 h-4 w-4" />
                    {{ team.agents_count ?? 3 }} agents
                </div>
            </CardContent>
        </Card>
    </Link>
</template>
