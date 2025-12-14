<script setup lang="ts">
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { AgentType, AgentTypeOption } from '@/types/agents';
import { Bot, Brain, Zap } from 'lucide-vue-next';

defineProps<{
    agentTypes: AgentTypeOption[];
}>();

const emit = defineEmits<{
    select: [type: AgentType];
}>();

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

const agentColor = (type: AgentType) => {
    switch (type) {
        case 'triage':
            return 'text-blue-500';
        case 'knowledge':
            return 'text-purple-500';
        case 'action':
            return 'text-orange-500';
        default:
            return 'text-muted-foreground';
    }
};
</script>

<template>
    <div class="grid gap-4 md:grid-cols-3">
        <Card
            v-for="type in agentTypes"
            :key="type.value"
            class="cursor-pointer transition-all hover:border-primary hover:shadow-md"
            @click="emit('select', type.value)"
        >
            <CardHeader>
                <div class="mb-2">
                    <component
                        :is="agentIcon(type.value)"
                        class="h-8 w-8"
                        :class="agentColor(type.value)"
                    />
                </div>
                <CardTitle class="text-lg">{{ type.label }}</CardTitle>
                <CardDescription>{{ type.description }}</CardDescription>
            </CardHeader>
        </Card>
    </div>
</template>
