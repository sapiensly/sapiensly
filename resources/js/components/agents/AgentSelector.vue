<script setup lang="ts">
import * as AgentController from '@/actions/App/Http/Controllers/AgentController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { AgentType, AgentTypeOption } from '@/types/agents';
import { Link } from '@inertiajs/vue3';
import { Bot, Brain, Plus, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

interface AgentOption {
    id: string;
    name: string;
    description: string | null;
    model: string;
    status: string;
}

interface Props {
    type: AgentType;
    typeInfo: AgentTypeOption;
    agents: AgentOption[];
    modelValue: string | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const icons = {
    triage: Bot,
    knowledge: Brain,
    action: Zap,
};

const typeIcon = computed(() => icons[props.type]);

const createUrl = computed(() => {
    return AgentController.create({ query: { type: props.type } }).url;
});
</script>

<template>
    <Card :class="['transition-all', modelValue ? 'ring-2 ring-primary' : '']">
        <CardHeader class="pb-3">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-primary/10 p-2">
                    <component :is="typeIcon" class="h-5 w-5 text-primary" />
                </div>
                <div>
                    <CardTitle class="text-base">{{
                        typeInfo.label
                    }}</CardTitle>
                    <CardDescription class="text-xs">{{
                        typeInfo.description
                    }}</CardDescription>
                </div>
            </div>
        </CardHeader>
        <CardContent>
            <div v-if="agents.length === 0" class="py-4 text-center">
                <p class="mb-3 text-sm text-muted-foreground">
                    No {{ typeInfo.label.toLowerCase() }}s available
                </p>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="createUrl">
                        <Plus class="mr-2 h-4 w-4" />
                        Create {{ typeInfo.label }}
                    </Link>
                </Button>
            </div>

            <div v-else class="space-y-3">
                <RadioGroup
                    :model-value="modelValue ?? undefined"
                    @update:model-value="emit('update:modelValue', $event)"
                    class="gap-2"
                >
                    <div
                        v-for="agent in agents"
                        :key="agent.id"
                        :class="[
                            'flex cursor-pointer items-center space-x-3 rounded-lg border p-3 transition-colors',
                            modelValue === agent.id
                                ? 'border-primary bg-primary/5'
                                : 'hover:bg-muted/50',
                        ]"
                        @click="emit('update:modelValue', agent.id)"
                    >
                        <RadioGroupItem :value="agent.id" :id="agent.id" />
                        <Label :for="agent.id" class="flex-1 cursor-pointer">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{
                                    agent.name
                                }}</span>
                                <Badge variant="outline" class="text-xs">
                                    {{ agent.status }}
                                </Badge>
                            </div>
                            <p
                                v-if="agent.description"
                                class="mt-0.5 line-clamp-1 text-xs text-muted-foreground"
                            >
                                {{ agent.description }}
                            </p>
                        </Label>
                    </div>
                </RadioGroup>

                <div class="border-t pt-2">
                    <Button variant="ghost" size="sm" class="w-full" as-child>
                        <Link :href="createUrl">
                            <Plus class="mr-2 h-4 w-4" />
                            Create New {{ typeInfo.label }}
                        </Link>
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
