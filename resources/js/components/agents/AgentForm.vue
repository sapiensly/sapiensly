<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { AgentFormData, AgentTypeOption, ModelOption } from '@/types/agents';
import { Bot, Brain, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    agent: AgentFormData;
    index: number;
    agentTypes: AgentTypeOption[];
    availableModels: ModelOption[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'update:agent', value: AgentFormData): void;
}>();

const agentType = computed(
    () => props.agentTypes.find((t) => t.value === props.agent.type),
);

const icon = computed(() => {
    switch (props.agent.type) {
        case 'triage':
            return Bot;
        case 'knowledge':
            return Brain;
        case 'action':
            return Zap;
        default:
            return Bot;
    }
});

const updateField = (field: keyof AgentFormData, value: unknown) => {
    emit('update:agent', {
        ...props.agent,
        [field]: value,
    });
};

const fieldName = (field: string) => `agents.${props.index}.${field}`;
const getError = (field: string) => props.errors[fieldName(field)];
</script>

<template>
    <div class="space-y-6 rounded-lg border p-6">
        <HeadingSmall
            :title="agentType?.label ?? 'Agent'"
            :description="agentType?.description"
        >
            <template #icon>
                <component :is="icon" class="h-5 w-5 text-muted-foreground" />
            </template>
        </HeadingSmall>

        <input type="hidden" :name="fieldName('type')" :value="agent.type" />

        <div class="grid gap-4 md:grid-cols-2">
            <div class="grid gap-2">
                <Label :for="`agent-${index}-name`">Name</Label>
                <Input
                    :id="`agent-${index}-name`"
                    :name="fieldName('name')"
                    :default-value="agent.name"
                    required
                    placeholder="Agent name"
                    @input="updateField('name', ($event.target as HTMLInputElement).value)"
                />
                <InputError :message="getError('name')" />
            </div>

            <div class="grid gap-2">
                <Label :for="`agent-${index}-model`">Model</Label>
                <Select
                    :default-value="agent.model"
                    :name="fieldName('model')"
                    @update:model-value="updateField('model', $event)"
                >
                    <SelectTrigger :id="`agent-${index}-model`">
                        <SelectValue placeholder="Select a model" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="model in availableModels"
                            :key="model.value"
                            :value="model.value"
                        >
                            {{ model.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="getError('model')" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label :for="`agent-${index}-description`">Description</Label>
            <Input
                :id="`agent-${index}-description`"
                :name="fieldName('description')"
                :default-value="agent.description"
                placeholder="What does this agent do?"
                @input="updateField('description', ($event.target as HTMLInputElement).value)"
            />
            <InputError :message="getError('description')" />
        </div>

        <div class="grid gap-2">
            <Label :for="`agent-${index}-prompt`">Prompt Template</Label>
            <Textarea
                :id="`agent-${index}-prompt`"
                :name="fieldName('prompt_template')"
                :default-value="agent.prompt_template"
                placeholder="Enter the system prompt for this agent..."
                rows="4"
                @input="updateField('prompt_template', ($event.target as HTMLTextAreaElement).value)"
            />
            <InputError :message="getError('prompt_template')" />
        </div>
    </div>
</template>
